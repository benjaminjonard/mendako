<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AutoTagConfigTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
        // Keep the same kernel across requests so a stubbed service/provider persists.
        $this->client->disableReboot();
    }

    /** Replace the service client with a bare stub so tests never hit the network. */
    private function stubClient(): void
    {
        static::getContainer()->set(AutoTagInferenceClient::class, $this->createStub(AutoTagInferenceClient::class));
    }

    /** Force the env-driven feature flag on (it defaults off in the test env). */
    private function setEnabled(bool $enabled): void
    {
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);
        $provider->method('getActiveModel')->willReturnMap([['wd', 'wd-eva02-large-tagger-v3'], ['clip', 'siglip2-so400m']]);
        static::getContainer()->set(AutoTagConfigProvider::class, $provider);
    }

    public function test_tag_backlog_button_starts_a_run_when_enabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->stubClient();
        $this->setEnabled(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin');
        $this->client->submit($crawler->filter('form[action$="tag-backlog"] button[value="1"]')->form());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Retroactive tagging started', $this->client->getResponse()->getContent());
    }

    public function test_jobs_block_is_hidden_when_disabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->stubClient();
        $this->setEnabled(false);

        $this->client->request(Request::METHOD_GET, '/admin');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Disabled', $content);
        $this->assertStringNotContainsString('/admin/autotag/tag-backlog', $content); // no actions exposed
    }

    public function test_batch_status_returns_processed_total(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $board = \App\Tests\Factory\BoardFactory::createOne();
        \App\Tests\Factory\PostFactory::createOne(['board' => $board]); // unprocessed
        $processed = \App\Tests\Factory\PostFactory::createOne(['board' => $board]);
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->persist((new \App\Entity\TagSuggestion())->setTargetType('post')->setTargetId($processed->getId())->setTagName('cat')->setScore(0.9)->setSource(\App\Entity\TagSuggestion::SOURCE_WD));
        $em->flush();

        $this->client->request(Request::METHOD_GET, '/admin/autotag/batch-status');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['processed']);
    }

    public function test_cache_status_returns_coverage_and_progress(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('vocabularyMissing')->willReturn(['cached' => 5, 'missing' => 2, 'total' => 7]);
        $client->method('vocabularyStatus')->willReturn(['running' => true, 'done' => 5, 'total' => 7]);
        static::getContainer()->set(AutoTagInferenceClient::class, $client);
        $this->setEnabled(true);

        $this->client->request(Request::METHOD_GET, '/admin/autotag/cache-status');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(5, $data['cached']);
        $this->assertSame(2, $data['missing']);
        $this->assertTrue($data['running']);
        $this->assertSame(5, $data['done']);
    }

    public function test_cache_encode_missing_triggers_incremental_warm_up(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->once())->method('warmVocabulary')->with($this->anything(), $this->anything(), false);
        static::getContainer()->set(AutoTagInferenceClient::class, $client);
        $this->setEnabled(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin');
        $this->client->submit($crawler->filter('form[action$="cache-encode"] button[value="0"]')->form());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Tag encoding started', $this->client->getResponse()->getContent());
    }

    public function test_cache_encode_all_re_encodes_everything(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->once())->method('warmVocabulary')->with($this->anything(), $this->anything(), true);
        static::getContainer()->set(AutoTagInferenceClient::class, $client);
        $this->setEnabled(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin');
        $this->client->submit($crawler->filter('form[action$="cache-encode"] button[value="1"]')->form());

        $this->assertResponseIsSuccessful();
    }

    public function test_is_enabled_reflects_the_env_flag(): void
    {
        $this->assertTrue((new AutoTagConfigProvider(true))->isEnabled());
        $this->assertFalse((new AutoTagConfigProvider(false))->isEnabled());
    }

    public function test_service_url_uses_env_or_built_in_default(): void
    {
        $this->assertSame('http://custom:9000', (new AutoTagConfigProvider(false, 'http://custom:9000'))->getServiceUrl());
        $this->assertSame('http://mendako_ml:8000', (new AutoTagConfigProvider(false, ''))->getServiceUrl());
    }

    public function test_get_active_model_comes_from_the_static_catalog(): void
    {
        // One model per category, baked into the service image — no DB selection involved.
        $provider = new AutoTagConfigProvider(true);
        $this->assertSame('wd-eva02-large-tagger-v3', $provider->getActiveModel('wd'));
        $this->assertSame('siglip2-so400m', $provider->getActiveModel('clip'));
        $this->assertNull($provider->getActiveModel('unknown'));
    }

    public function test_admin_dashboard_shows_jobs_block_when_enabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->stubClient();
        $this->setEnabled(true);

        $this->client->request(Request::METHOD_GET, '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_admin_index');
        $this->assertStringContainsString('/admin/autotag/tag-backlog', $this->client->getResponse()->getContent());
    }
}

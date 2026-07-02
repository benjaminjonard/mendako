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

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/jobs');
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

    public function test_jobs_endpoint_returns_processed_total_per_job(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $board = \App\Tests\Factory\BoardFactory::createOne();
        \App\Tests\Factory\PostFactory::createOne(['board' => $board]); // unprocessed
        $processed = \App\Tests\Factory\PostFactory::createOne(['board' => $board]);
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->persist((new \App\Entity\TagSuggestion())->setTargetType('post')->setTargetId($processed->getId())->setTagName('cat')->setScore(0.9)->setSource(\App\Entity\TagSuggestion::SOURCE_WD));
        $em->flush();

        $this->client->request(Request::METHOD_GET, '/admin/autotag/jobs');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        // A single request now carries every job card's status.
        $this->assertSame(2, $data['tagging']['total']);
        $this->assertSame(1, $data['tagging']['processed']);
        $this->assertArrayHasKey('embedding', $data);
        $this->assertSame(2, $data['embedding']['total']);
        // Duplicate-detection vectors: both factory posts lack a vector (not built at factory time).
        $this->assertArrayHasKey('vectors', $data);
        $this->assertSame(2, $data['vectors']['total']);
        $this->assertSame(0, $data['vectors']['processed']);
    }

    public function test_vector_backlog_button_starts_a_run_even_when_autotag_disabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->stubClient();
        // Duplicate detection is a core feature: the recompute must work with auto-tagging OFF.
        $this->setEnabled(false);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/jobs');
        $this->client->submit($crawler->filter('form[action$="vectors/backlog"] button[value="1"]')->form());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Vector recompute started', $this->client->getResponse()->getContent());
    }

    public function test_cancel_button_cancels_a_run(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->stubClient();
        // Vectors card (and its cancel form) is always rendered, auto-tagging on or off.
        $this->setEnabled(false);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/jobs');
        // Submit the form node itself: the cancel button ships disabled (JS enables it live), so
        // clicking it in the crawler wouldn't post — the form still carries the CSRF token.
        $this->client->submit($crawler->filter('form[action$="jobs/vectors/cancel"]')->form());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Job cancelled', $this->client->getResponse()->getContent());
    }


    public function test_embed_backlog_button_starts_a_run_when_enabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->setEnabled(true);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/jobs');
        $this->client->submit($crawler->filter('form[action$="embed-backlog"] button[value="1"]')->form());

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
        // WD is also the embedding encoder now — there is no separate 'clip' model.
        $this->assertNull($provider->getActiveModel('clip'));
        $this->assertNull($provider->getActiveModel('unknown'));
    }

    public function test_admin_dashboard_shows_jobs_block_when_enabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->stubClient();
        $this->setEnabled(true);

        $this->client->request(Request::METHOD_GET, '/admin/jobs');

        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_admin_jobs');
        $this->assertStringContainsString('/admin/autotag/tag-backlog', $this->client->getResponse()->getContent());
    }
}

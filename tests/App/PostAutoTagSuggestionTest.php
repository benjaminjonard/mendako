<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PostAutoTagSuggestionTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
        // Keep the same kernel across requests so a stubbed provider persists.
        $this->client->disableReboot();
    }

    private function enableAutoTag(bool $enabled): void
    {
        $stub = $this->createStub(AutoTagConfigProvider::class);
        $stub->method('isEnabled')->willReturn($enabled);
        // Without this the stub returns 0.0, auto-validating every suggestion; pin it to the default.
        $stub->method('getAutoValidateThreshold')->willReturn(0.85);
        static::getContainer()->set(AutoTagConfigProvider::class, $stub);
    }

    private function createPost(): array
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        return [$board, $post];
    }

    private function persistSuggestion(string $targetId, string $name, float $score, TagCategory $category, string $status = TagSuggestion::STATUS_PENDING, string $source = TagSuggestion::SOURCE_WD): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suggestion = (new TagSuggestion())
            ->setTargetType('post')
            ->setTargetId($targetId)
            ->setTagName($name)
            ->setCategory($category)
            ->setScore($score)
            ->setSource($source)
            ->setStatus($status);
        $em->persist($suggestion);
        $em->flush();
    }

    public function test_endpoint_returns_high_pending_split_when_enabled(): void
    {
        [$board, $post] = $this->createPost();
        $this->enableAutoTag(true);
        $this->persistSuggestion($post->getId(), 'hatsune_miku', 0.95, TagCategory::CHARACTER);
        $this->persistSuggestion($post->getId(), '1girl', 0.50, TagCategory::GENERAL);
        $this->persistSuggestion($post->getId(), 'rejected_tag', 0.99, TagCategory::GENERAL, TagSuggestion::STATUS_DISMISSED);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug().'/'.$post->getId().'/autotag-suggestions');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['enabled']);
        $this->assertSame('ready', $data['status']);
        $this->assertSame(['hatsune_miku'], array_column($data['highConfidence'], 'name'));
        $this->assertSame(['1girl'], array_column($data['pending'], 'name'));
        // Dismissed suggestions are never surfaced.
        $this->assertNotContains('rejected_tag', array_column($data['highConfidence'], 'name'));
    }

    public function test_knn_suggestion_is_never_prefilled_even_when_confident(): void
    {
        [$board, $post] = $this->createPost();
        $this->enableAutoTag(true);
        // A high-scoring knn suggestion must NOT be pre-filled — it is a learned guess, not a
        // confident tag. Only wd tags are pre-filled.
        $this->persistSuggestion($post->getId(), 'knn_character', 0.95, TagCategory::CHARACTER, source: TagSuggestion::SOURCE_KNN);
        $this->persistSuggestion($post->getId(), 'wd_tag', 0.95, TagCategory::GENERAL);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug().'/'.$post->getId().'/autotag-suggestions');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(['wd_tag'], array_column($data['highConfidence'], 'name'));
        $this->assertContains('knn_character', array_column($data['pending'], 'name'));
    }

    public function test_store_skips_tags_already_applied_to_the_post(): void
    {
        [, $post] = $this->createPost();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // A tag already confirmed on the post must not be re-proposed on a re-run.
        $existing = TagFactory::createOne(['name' => 'existing_tag', 'category' => TagCategory::GENERAL]);
        $managedPost = $em->getRepository(\App\Entity\Post::class)->find($post->getId());
        // The transient upload file was already consumed on creation; clear it so re-flushing
        // to attach the tag doesn't retrigger the upload listener on a now-missing temp file.
        $managedPost->setFile(null);
        $managedPost->addTag($existing);
        $em->flush();

        $store = static::getContainer()->get(\App\Service\AutoTag\SuggestionService::class);
        $store->store('post', $post->getId(), ['tags' => [
            ['name' => 'existing_tag', 'score' => 0.95, 'category' => 'general'],
            ['name' => 'new_tag', 'score' => 0.90, 'category' => 'general'],
        ]]);

        $names = array_map(
            static fn (TagSuggestion $s): string => $s->getTagName(),
            static::getContainer()->get(\App\Repository\TagSuggestionRepository::class)->findForTarget('post', $post->getId()),
        );
        $this->assertContains('new_tag', $names);
        $this->assertNotContains('existing_tag', $names);
    }

    public function test_endpoint_returns_disabled_when_feature_off(): void
    {
        [$board, $post] = $this->createPost();
        $this->enableAutoTag(false);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug().'/'.$post->getId().'/autotag-suggestions');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['enabled']);
        $this->assertArrayNotHasKey('highConfidence', $data);
    }

    public function test_endpoint_returns_analyzing_when_no_suggestions_yet(): void
    {
        [$board, $post] = $this->createPost();
        $this->enableAutoTag(true);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug().'/'.$post->getId().'/autotag-suggestions');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('analyzing', $data['status']);
        $this->assertSame([], $data['highConfidence']);
        $this->assertSame([], $data['pending']);
    }

    public function test_edit_form_exposes_suggestions_url_when_enabled(): void
    {
        [$board, $post] = $this->createPost();
        $this->enableAutoTag(true);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug().'/'.$post->getId().'/edit');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-suggestions-url-value', $content);
        $this->assertStringContainsString('data-suggestions-target="analyzing"', $content);
        $this->assertStringContainsString('data-suggestions-target="pending"', $content);
    }

    public function test_edit_form_has_no_ai_markup_when_disabled(): void
    {
        [$board, $post] = $this->createPost();
        $this->enableAutoTag(false);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug().'/'.$post->getId().'/edit');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('data-suggestions-url-value', $content);
        $this->assertStringNotContainsString('data-suggestions-target="analyzing"', $content);
    }
}

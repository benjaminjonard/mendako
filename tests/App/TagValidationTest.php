<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Post;
use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use App\Repository\PostRepository;
use App\Repository\TagSuggestionRepository;
use App\Service\AutoTag\SuggestionService;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TagValidationTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
        $this->client->disableReboot();
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
    }

    private function createPost(): Post
    {
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);

        return PostFactory::createOne(['board' => $board, 'file' => $uploadedFile]);
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

    public function test_requires_admin_role(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER']]));

        $this->client->request(Request::METHOD_GET, '/tag-validation');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_empty_state_when_nothing_to_validate(): void
    {
        $this->loginAdmin();

        $this->client->request(Request::METHOD_GET, '/tag-validation');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'No posts left to validate.');
    }

    public function test_queue_shows_a_post_with_pending_suggestions(): void
    {
        $this->loginAdmin();
        $post = $this->createPost();
        // Confident wd tag pre-fills the field; the low-confidence one becomes a list row.
        $this->persistSuggestion($post->getId(), 'hatsune_miku', 0.95, TagCategory::CHARACTER);
        $this->persistSuggestion($post->getId(), '1girl', 0.20, TagCategory::GENERAL);

        $crawler = $this->client->request(Request::METHOD_GET, '/tag-validation');

        $this->assertResponseIsSuccessful();
        // The post image is on screen.
        $this->assertStringContainsString($post->getPath(), $this->client->getResponse()->getContent());
        // High-confidence suggestion pre-filled into the tags textarea.
        $this->assertStringContainsString('hatsune_miku', $crawler->filter('#tag_validation_tags')->text());

        // Low-confidence suggestion offered as a list row (not pre-filled) with add/discard controls.
        $row = $crawler->filter('li.suggestion-row[data-suggestion="1girl"]');
        $this->assertCount(1, $row);
        $this->assertSame('1girl', $row->filter('.suggestion-name')->text());
        $this->assertCount(1, $row->filter('[data-action*="suggestions#acceptSuggestion"]'));
        $this->assertCount(1, $row->filter('[data-action*="suggestions#rejectSuggestion"]'));
        // Source badge and score percentage are shown.
        $this->assertSame('WD', $row->filter('.suggestion-source')->text());
        $this->assertStringContainsString('20%', $row->filter('.suggestion-score')->text());
    }

    public function test_submit_writes_real_tags_and_resolves_suggestion_statuses(): void
    {
        $this->loginAdmin();
        $post = $this->createPost();
        $this->persistSuggestion($post->getId(), 'hatsune_miku', 0.95, TagCategory::CHARACTER);
        $this->persistSuggestion($post->getId(), '1girl', 0.50, TagCategory::GENERAL);

        $crawler = $this->client->request(Request::METHOD_GET, '/tag-validation');
        $form = $crawler->filter('form')->form();
        // Keep hatsune_miku, add a manual tag, drop the 1girl suggestion.
        $form['tag_validation[tags]'] = 'hatsune_miku solo';
        $this->client->submit($form);

        // followRedirects lands us on the next queue page (here: the empty state).
        $this->assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        // Kept + manual tags became real tags on the post.
        $saved = static::getContainer()->get(PostRepository::class)->find($post->getId());
        $tagNames = array_map(static fn ($tag): string => $tag->getName(), $saved->getTags()->toArray());
        sort($tagNames);
        $this->assertSame(['hatsune_miku', 'solo'], $tagNames);

        // Suggestions transitioned to terminal statuses: kept → accepted, offered-but-dropped → dismissed.
        $statusByName = $this->statusByName($post->getId());
        $this->assertSame(TagSuggestion::STATUS_ACCEPTED, $statusByName['hatsune_miku']);
        $this->assertSame(TagSuggestion::STATUS_DISMISSED, $statusByName['1girl']);

        // Nothing pending remains → the post leaves the validation queue.
        $this->assertNull(static::getContainer()->get(PostRepository::class)->findLatestWithPendingSuggestions());
    }

    public function test_submit_with_empty_tags_dismisses_all_suggestions(): void
    {
        $this->loginAdmin();
        $post = $this->createPost();
        $this->persistSuggestion($post->getId(), '1girl', 0.50, TagCategory::GENERAL);

        $crawler = $this->client->request(Request::METHOD_GET, '/tag-validation');
        $form = $crawler->filter('form')->form();
        $form['tag_validation[tags]'] = '';
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();

        // Reviewing is the action: clearing the field still validates — every suggestion is dismissed.
        $this->assertSame(['1girl' => TagSuggestion::STATUS_DISMISSED], $this->statusByName($post->getId()));
        $this->assertNull(static::getContainer()->get(PostRepository::class)->findLatestWithPendingSuggestions());
    }

    public function test_dismissed_suggestion_is_not_resurfaced_on_autotag_rerun(): void
    {
        $this->loginAdmin();
        $post = $this->createPost();
        $this->persistSuggestion($post->getId(), '1girl', 0.50, TagCategory::GENERAL);

        // Reviewer validates without keeping 1girl → the suggestion is dismissed.
        $crawler = $this->client->request(Request::METHOD_GET, '/tag-validation');
        $form = $crawler->filter('form')->form();
        $form['tag_validation[tags]'] = '';
        $this->client->submit($form);

        // Auto-tag runs again and re-proposes 1girl with high confidence...
        static::getContainer()->get(SuggestionService::class)->store('post', $post->getId(), [
            'tags' => [['name' => '1girl', 'category' => 'general', 'score' => 0.9]],
        ]);

        // ...but a dismissed name is never re-surfaced as a fresh pending suggestion.
        $this->assertSame(['1girl' => TagSuggestion::STATUS_DISMISSED], $this->statusByName($post->getId()));
        $this->assertNull(static::getContainer()->get(PostRepository::class)->findLatestWithPendingSuggestions());
    }

    public function test_validation_tab_and_pending_badge_appear_in_tags_submenu(): void
    {
        $this->loginAdmin();
        $post = $this->createPost();
        $this->persistSuggestion($post->getId(), '1girl', 0.50, TagCategory::GENERAL);

        $crawler = $this->client->request(Request::METHOD_GET, '/tags');

        $this->assertResponseIsSuccessful();
        // Validation lives as a tab in the Tags submenu.
        $tab = $crawler->filter('.tabs a[href="/tag-validation"]');
        $this->assertCount(1, $tab);
        // ...with an is-info badge counting the one post waiting to be validated.
        $this->assertSame('1', $tab->filter('.tag.is-info')->text());
    }

    public function test_no_badge_when_validation_queue_is_empty(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request(Request::METHOD_GET, '/tags');

        $this->assertResponseIsSuccessful();
        $tab = $crawler->filter('.tabs a[href="/tag-validation"]');
        $this->assertCount(1, $tab);
        // Empty queue → the tab is there, but no count badge.
        $this->assertCount(0, $tab->filter('.tag'));
    }

    public function test_count_posts_with_pending_suggestions_is_per_post(): void
    {
        $repo = static::getContainer()->get(PostRepository::class);
        $this->assertSame(0, $repo->countPostsWithPendingSuggestions());

        $post = $this->createPost();
        // Two pending suggestions on the same post count as one post in the queue.
        $this->persistSuggestion($post->getId(), '1girl', 0.50, TagCategory::GENERAL);
        $this->persistSuggestion($post->getId(), 'solo', 0.50, TagCategory::GENERAL);
        $this->assertSame(1, $repo->countPostsWithPendingSuggestions());

        // A resolved suggestion doesn't keep a post in the queue.
        $resolved = $this->createPost();
        $this->persistSuggestion($resolved->getId(), 'cat', 0.90, TagCategory::GENERAL, TagSuggestion::STATUS_ACCEPTED);
        $this->assertSame(1, $repo->countPostsWithPendingSuggestions());
    }

    private function statusByName(string $targetId): array
    {
        $statuses = [];
        foreach (static::getContainer()->get(TagSuggestionRepository::class)->findForTarget('post', $targetId) as $suggestion) {
            $statuses[$suggestion->getTagName()] = $suggestion->getStatus();
        }

        return $statuses;
    }
}

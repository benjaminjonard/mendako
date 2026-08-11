<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Post;
use App\Entity\Tag;
use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use App\Repository\TagSuggestionRepository;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DeleteSuggestedTagsCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private PostRepository $postRepository;
    private TagRepository $tagRepository;
    private TagSuggestionRepository $suggestionRepository;
    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->postRepository = static::getContainer()->get(PostRepository::class);
        $this->tagRepository = static::getContainer()->get(TagRepository::class);
        $this->suggestionRepository = static::getContainer()->get(TagSuggestionRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:tag:delete-suggested'));
    }

    private function createPost(array $tags): Post
    {
        return PostFactory::createOne([
            'board' => BoardFactory::createOne(),
            'uploadedBy' => UserFactory::createOne(),
            'tags' => $tags,
        ]);
    }

    private function persistSuggestion(
        string $tagName,
        string $targetId,
        string $status = TagSuggestion::STATUS_ACCEPTED,
    ): TagSuggestion {
        $suggestion = (new TagSuggestion())
            ->setTargetType('post')
            ->setTargetId($targetId)
            ->setTagName($tagName)
            ->setCategory(TagCategory::GENERAL)
            ->setScore(0.9)
            ->setSource(TagSuggestion::SOURCE_WD)
            ->setStatus($status)
        ;
        $this->entityManager->persist($suggestion);
        $this->entityManager->flush();

        return $suggestion;
    }

    /**
     * @return string[]
     */
    private function tagNamesOf(string $postId): array
    {
        $names = [];
        foreach ($this->postRepository->find($postId)->getTags() as $tag) {
            $names[] = $tag->getName();
        }
        sort($names);

        return $names;
    }

    public function test_purge_detaches_auto_applied_tags_and_keeps_hand_typed_ones(): void
    {
        // `source` is not the criterion: a name the model emits can also have been typed by hand.
        $autoTag = TagFactory::createOne(['name' => 'smile', 'source' => Tag::SOURCE_WD]);
        $handTag = TagFactory::createOne(['name' => 'my_own_tag', 'source' => Tag::SOURCE_CUSTOM]);

        $autoTagged = $this->createPost([$autoTag, $handTag]);
        $handTagged = $this->createPost([$autoTag]);

        // Only the first post got 'smile' from auto-tagging.
        $this->persistSuggestion('smile', $autoTagged->getId());

        $tester = $this->tester();
        $tester->execute(['--force' => true]);
        $tester->assertCommandIsSuccessful();

        $this->entityManager->clear();

        self::assertSame(['my_own_tag'], $this->tagNamesOf($autoTagged->getId()));
        // Same tag, but on a post no suggestion ever targeted -> untouched.
        self::assertSame(['smile'], $this->tagNamesOf($handTagged->getId()));
        // Still carried by a post, so the tag itself survives.
        self::assertNotNull($this->tagRepository->findOneBy(['name' => 'smile']));
        self::assertNotNull($this->tagRepository->findOneBy(['name' => 'my_own_tag']));
    }

    public function test_purge_deletes_tags_left_on_no_post_and_empties_the_history(): void
    {
        $onlyAuto = TagFactory::createOne(['name' => 'solo', 'source' => Tag::SOURCE_WD]);
        $post = $this->createPost([$onlyAuto]);

        $accepted = $this->persistSuggestion('solo', $post->getId());
        $pending = $this->persistSuggestion('1girl', $post->getId(), TagSuggestion::STATUS_PENDING);
        $dismissed = $this->persistSuggestion('bad_tag', $post->getId(), TagSuggestion::STATUS_DISMISSED);

        $tester = $this->tester();
        $tester->execute(['--force' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Detached 1 tag(s)', $tester->getDisplay());

        $this->entityManager->clear();

        self::assertSame([], $this->tagNamesOf($post->getId()));
        self::assertNull($this->tagRepository->findOneBy(['name' => 'solo']));
        // Every status goes, whatever the target: the validation queue comes out empty.
        self::assertNull($this->suggestionRepository->find($accepted->getId()));
        self::assertNull($this->suggestionRepository->find($pending->getId()));
        self::assertNull($this->suggestionRepository->find($dismissed->getId()));
    }

    public function test_pending_suggestion_alone_never_detaches_a_tag(): void
    {
        // The user typed 'smile' himself; the model merely also suggested it, still pending.
        $tag = TagFactory::createOne(['name' => 'smile', 'source' => Tag::SOURCE_WD]);
        $post = $this->createPost([$tag]);
        $pending = $this->persistSuggestion('smile', $post->getId(), TagSuggestion::STATUS_PENDING);

        $tester = $this->tester();
        $tester->execute(['--force' => true]);
        $tester->assertCommandIsSuccessful();

        $this->entityManager->clear();

        self::assertSame(['smile'], $this->tagNamesOf($post->getId()));
        self::assertNotNull($this->tagRepository->findOneBy(['name' => 'smile']));
        self::assertNull($this->suggestionRepository->find($pending->getId()));
    }

    public function test_dry_run_reports_the_volume_without_deleting_anything(): void
    {
        $tag = TagFactory::createOne(['name' => 'solo', 'source' => Tag::SOURCE_WD]);
        $post = $this->createPost([$tag]);
        $suggestion = $this->persistSuggestion('solo', $post->getId());

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('Would detach 1 tag(s)', $tester->getDisplay());

        $this->entityManager->clear();

        self::assertSame(['solo'], $this->tagNamesOf($post->getId()));
        self::assertNotNull($this->suggestionRepository->find($suggestion->getId()));
    }

    public function test_declined_confirmation_deletes_nothing(): void
    {
        $tag = TagFactory::createOne(['name' => 'solo', 'source' => Tag::SOURCE_WD]);
        $post = $this->createPost([$tag]);
        $suggestion = $this->persistSuggestion('solo', $post->getId());

        $tester = $this->tester();
        $tester->setInputs(['no']);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('nothing was deleted', $tester->getDisplay());

        $this->entityManager->clear();

        self::assertSame(['solo'], $this->tagNamesOf($post->getId()));
        self::assertNotNull($this->suggestionRepository->find($suggestion->getId()));
    }

    public function test_non_interactive_run_without_force_is_refused(): void
    {
        $tag = TagFactory::createOne(['name' => 'solo', 'source' => Tag::SOURCE_WD]);
        $post = $this->createPost([$tag]);
        $suggestion = $this->persistSuggestion('solo', $post->getId());

        $tester = $this->tester();
        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('pass --force', $tester->getDisplay());

        $this->entityManager->clear();

        self::assertSame(['solo'], $this->tagNamesOf($post->getId()));
        self::assertNotNull($this->suggestionRepository->find($suggestion->getId()));
    }

    public function test_nothing_to_delete_is_reported_as_a_success(): void
    {
        $this->createPost([TagFactory::createOne(['name' => 'my_own_tag'])]);

        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('No suggested tag to delete', $tester->getDisplay());
    }
}

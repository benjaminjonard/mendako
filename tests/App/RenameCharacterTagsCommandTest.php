<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
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
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class RenameCharacterTagsCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private TagRepository $tagRepository;
    private TagSuggestionRepository $suggestionRepository;
    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->tagRepository = static::getContainer()->get(TagRepository::class);
        $this->suggestionRepository = static::getContainer()->get(TagSuggestionRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:tag:rename-characters'));
    }

    private function persistSuggestion(
        string $tagName,
        string $status = TagSuggestion::STATUS_PENDING,
        string $source = TagSuggestion::SOURCE_WD,
        ?string $targetId = null,
        ?TagCategory $category = TagCategory::CHARACTER,
    ): TagSuggestion {
        $suggestion = (new TagSuggestion())
            ->setTargetType('post')
            ->setTargetId($targetId ?? Uuid::v7()->toRfc4122())
            ->setTagName($tagName)
            ->setCategory($category)
            ->setScore(0.9)
            ->setSource($source)
            ->setStatus($status)
        ;
        $this->entityManager->persist($suggestion);
        $this->entityManager->flush();

        return $suggestion;
    }

    public function test_rename_syncs_matching_suggestions_across_statuses(): void
    {
        $character = TagFactory::createOne(['name' => 'nero', 'category' => TagCategory::CHARACTER]);
        $copyright = TagFactory::createOne(['name' => 'fate', 'category' => TagCategory::COPYRIGHT]);
        PostFactory::createOne([
            'board' => BoardFactory::createOne(),
            'uploadedBy' => UserFactory::createOne(),
            'tags' => [$character, $copyright],
        ]);

        $pending = $this->persistSuggestion('nero', TagSuggestion::STATUS_PENDING);
        $decided = $this->persistSuggestion('nero', TagSuggestion::STATUS_ACCEPTED, TagSuggestion::SOURCE_WD);
        $untouched = $this->persistSuggestion('tamamo', TagSuggestion::STATUS_PENDING);

        $tester = $this->tester();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->entityManager->clear();

        // Tag renamed to its majority-copyright qualified form.
        self::assertNull($this->tagRepository->findOneBy(['name' => 'nero']));
        self::assertNotNull($this->tagRepository->findOneBy(['name' => 'nero_(fate)']));

        // Every suggestion carrying the old name follows it, regardless of status/source.
        self::assertSame('nero_(fate)', $this->suggestionRepository->find($pending->getId())->getTagName());
        self::assertSame('nero_(fate)', $this->suggestionRepository->find($decided->getId())->getTagName());
        // Unrelated names are left alone.
        self::assertSame('tamamo', $this->suggestionRepository->find($untouched->getId())->getTagName());
    }

    public function test_dry_run_changes_nothing_but_reports_impact(): void
    {
        $character = TagFactory::createOne(['name' => 'nero', 'category' => TagCategory::CHARACTER]);
        $copyright = TagFactory::createOne(['name' => 'fate', 'category' => TagCategory::COPYRIGHT]);
        PostFactory::createOne([
            'board' => BoardFactory::createOne(),
            'uploadedBy' => UserFactory::createOne(),
            'tags' => [$character, $copyright],
        ]);
        $pending = $this->persistSuggestion('nero', TagSuggestion::STATUS_PENDING);

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('would sync 1 suggestion', $tester->getDisplay());

        $this->entityManager->clear();
        self::assertNotNull($this->tagRepository->findOneBy(['name' => 'nero']));
        self::assertSame('nero', $this->suggestionRepository->find($pending->getId())->getTagName());
    }

    public function test_renameTagName_drops_row_that_would_collide_with_existing_target(): void
    {
        $targetId = Uuid::v7()->toRfc4122();
        // Same target + source already owns the destination name → the stale $from row is dropped.
        $stale = $this->persistSuggestion('nero', TagSuggestion::STATUS_PENDING, TagSuggestion::SOURCE_WD, $targetId);
        $existing = $this->persistSuggestion('nero_(fate)', TagSuggestion::STATUS_PENDING, TagSuggestion::SOURCE_WD, $targetId);
        // A $from row on a different target still gets renamed.
        $other = $this->persistSuggestion('nero', TagSuggestion::STATUS_PENDING, TagSuggestion::SOURCE_WD);

        $renamed = $this->suggestionRepository->renameTagName('nero', 'nero_(fate)');

        self::assertSame(1, $renamed);
        $this->entityManager->clear();
        self::assertNull($this->suggestionRepository->find($stale->getId()));
        self::assertSame('nero_(fate)', $this->suggestionRepository->find($existing->getId())->getTagName());
        self::assertSame('nero_(fate)', $this->suggestionRepository->find($other->getId())->getTagName());
    }

    public function test_countByTagName_counts_every_matching_row(): void
    {
        $this->persistSuggestion('nero', TagSuggestion::STATUS_PENDING);
        $this->persistSuggestion('nero', TagSuggestion::STATUS_DISMISSED, TagSuggestion::SOURCE_WD);
        $this->persistSuggestion('tamamo', TagSuggestion::STATUS_PENDING);

        self::assertSame(2, $this->suggestionRepository->countByTagName('nero'));
        self::assertSame(0, $this->suggestionRepository->countByTagName('unknown'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use App\Repository\TagSuggestionRepository;
use App\Service\AutoTag\SuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class SuggestionServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private SuggestionService $service;
    private TagSuggestionRepository $repository;
    private EntityManagerInterface $entityManager;
    private string $targetId;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(SuggestionService::class);
        $this->repository = $container->get(TagSuggestionRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->targetId = Uuid::v7()->toRfc4122();
    }

    public function test_store_creates_ranked_pending_suggestions_with_mapped_categories(): void
    {
        $this->service->store('post', $this->targetId, [
            'tags' => [
                ['name' => '1girl', 'category' => 'general', 'score' => 0.7],
                ['name' => 'Hatsune Miku', 'category' => 'character', 'score' => 0.95],
            ],
            'rating' => ['label' => 'general', 'score' => 0.8],
        ]);

        $suggestions = $this->repository->findForTarget('post', $this->targetId);

        $this->assertCount(3, $suggestions);
        // Ordered by score DESC.
        $this->assertSame(['hatsune_miku', 'general', '1girl'], array_map(static fn (TagSuggestion $s) => $s->getTagName(), $suggestions));
        foreach ($suggestions as $suggestion) {
            $this->assertSame(TagSuggestion::STATUS_PENDING, $suggestion->getStatus());
            $this->assertSame(TagSuggestion::SOURCE_WD, $suggestion->getSource());
        }

        $byName = $this->indexByName($suggestions);
        $this->assertSame(TagCategory::CHARACTER, $byName['hatsune_miku']->getCategory());
        $this->assertSame(TagCategory::GENERAL, $byName['1girl']->getCategory());
        // Rating mapped to the RATING category, not whatever string it carried.
        $this->assertSame(TagCategory::RATING, $byName['general']->getCategory());
        $this->assertSame(0.8, $byName['general']->getScore());
    }

    public function test_store_collapses_duplicate_tag_to_highest_score(): void
    {
        $this->service->store('post', $this->targetId, [
            'tags' => [
                ['name' => '1girl', 'category' => 'general', 'score' => 0.4],
                ['name' => '1girl', 'category' => 'general', 'score' => 0.9],
            ],
        ]);

        $suggestions = $this->repository->findForTarget('post', $this->targetId);

        $this->assertCount(1, $suggestions);
        $this->assertSame(0.9, $suggestions[0]->getScore());
    }

    public function test_store_skips_null_rating_label(): void
    {
        $this->service->store('post', $this->targetId, [
            'tags' => [['name' => '1girl', 'category' => 'general', 'score' => 0.5]],
            'rating' => ['label' => null, 'score' => 0.0],
        ]);

        $suggestions = $this->repository->findForTarget('post', $this->targetId);

        $this->assertCount(1, $suggestions);
        $this->assertSame('1girl', $suggestions[0]->getTagName());
    }

    public function test_rerun_replaces_pending_and_preserves_accepted(): void
    {
        $this->service->store('post', $this->targetId, [
            'tags' => [
                ['name' => 'old_tag', 'category' => 'general', 'score' => 0.6],
                ['name' => 'kept_tag', 'category' => 'general', 'score' => 0.7],
            ],
        ]);

        // The user accepts one suggestion.
        $accepted = $this->repository->findOneBy(['targetId' => $this->targetId, 'tagName' => 'kept_tag']);
        $accepted->setStatus(TagSuggestion::STATUS_ACCEPTED);
        $this->entityManager->flush();

        // Re-run with a fresh result set.
        $this->service->store('post', $this->targetId, [
            'tags' => [['name' => 'new_tag', 'category' => 'general', 'score' => 0.8]],
        ]);

        $suggestions = $this->repository->findForTarget('post', $this->targetId);
        $names = array_map(static fn (TagSuggestion $s) => $s->getTagName(), $suggestions);

        // Accepted row survives; stale pending 'old_tag' is gone; new pending added; no duplicate.
        $this->assertContains('kept_tag', $names);
        $this->assertContains('new_tag', $names);
        $this->assertNotContains('old_tag', $names);
        $this->assertCount(2, $suggestions);
        $this->assertSame(TagSuggestion::STATUS_ACCEPTED, $this->indexByName($suggestions)['kept_tag']->getStatus());
    }

    public function test_rerun_does_not_resurface_dismissed_or_duplicate_accepted(): void
    {
        $this->service->store('post', $this->targetId, [
            'tags' => [
                ['name' => 'dismissed_tag', 'category' => 'general', 'score' => 0.6],
                ['name' => 'accepted_tag', 'category' => 'general', 'score' => 0.7],
            ],
        ]);

        $byName = $this->indexByName($this->repository->findForTarget('post', $this->targetId));
        $byName['dismissed_tag']->setStatus(TagSuggestion::STATUS_DISMISSED);
        $byName['accepted_tag']->setStatus(TagSuggestion::STATUS_ACCEPTED);
        $this->entityManager->flush();

        // The model proposes the very same tags again.
        $this->service->store('post', $this->targetId, [
            'tags' => [
                ['name' => 'dismissed_tag', 'category' => 'general', 'score' => 0.95],
                ['name' => 'accepted_tag', 'category' => 'general', 'score' => 0.95],
                ['name' => 'fresh_tag', 'category' => 'general', 'score' => 0.5],
            ],
        ]);

        $suggestions = $this->repository->findForTarget('post', $this->targetId);
        $byName = $this->indexByName($suggestions);

        // No duplicates: exactly one row per name.
        $this->assertCount(3, $suggestions);
        // The dismissed tag stays dismissed (not re-surfaced as pending).
        $this->assertSame(TagSuggestion::STATUS_DISMISSED, $byName['dismissed_tag']->getStatus());
        // The accepted tag is untouched (not duplicated, score unchanged).
        $this->assertSame(TagSuggestion::STATUS_ACCEPTED, $byName['accepted_tag']->getStatus());
        $this->assertSame(0.7, $byName['accepted_tag']->getScore());
        // Only the genuinely new tag is added as pending.
        $this->assertSame(TagSuggestion::STATUS_PENDING, $byName['fresh_tag']->getStatus());
    }

    public function test_skips_tag_name_exceeding_column_length(): void
    {
        $longName = str_repeat('a', 300);
        $this->service->store('post', $this->targetId, [
            'tags' => [
                ['name' => $longName, 'category' => 'general', 'score' => 0.9],
                ['name' => '1girl', 'category' => 'general', 'score' => 0.8],
            ],
        ]);

        $suggestions = $this->repository->findForTarget('post', $this->targetId);

        $this->assertCount(1, $suggestions);
        $this->assertSame('1girl', $suggestions[0]->getTagName());
    }

    /**
     * @param TagSuggestion[] $suggestions
     *
     * @return array<string, TagSuggestion>
     */
    private function indexByName(array $suggestions): array
    {
        $indexed = [];
        foreach ($suggestions as $suggestion) {
            $indexed[$suggestion->getTagName()] = $suggestion;
        }

        return $indexed;
    }
}

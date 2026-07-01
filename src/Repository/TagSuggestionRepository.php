<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TagSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TagSuggestion::class);
    }

    /**
     * @return TagSuggestion[]
     */
    public function findForTarget(string $targetType, string $targetId): array
    {
        return $this->findBy(
            ['targetType' => $targetType, 'targetId' => $targetId],
            ['score' => 'DESC'],
        );
    }

    /**
     * Remove a target's still-pending suggestions for a given source, so a re-run
     * can replace them. Accepted/dismissed suggestions are left untouched.
     */
    public function deletePendingForTarget(string $targetType, string $targetId, string $source): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.targetType = :targetType')
            ->andWhere('s.targetId = :targetId')
            ->andWhere('s.source = :source')
            ->andWhere('s.status = :status')
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId)
            ->setParameter('source', $source)
            ->setParameter('status', TagSuggestion::STATUS_PENDING)
            ->getQuery()
            ->execute();
    }

    /**
     * Resolve a target's still-pending suggestions after human validation: names the reviewer
     * kept become ACCEPTED, everything else DISMISSED. Both are terminal — the post drops out of
     * the pending validation queue, and auto-tag re-runs keep these rows (deletePendingForTarget
     * only touches pending) and never re-surface their names, whatever the source
     * (decidedTagNamesForTarget matches terminal statuses across all sources). Bulk UPDATEs, so
     * run it when no matching suggestions are held in the UoW.
     *
     * @param string[] $acceptedNames tag names present on the item after validation
     */
    public function resolvePendingForTarget(string $targetType, string $targetId, array $acceptedNames): void
    {
        // Pass 1: accept the pending suggestions whose name the reviewer kept.
        if ($acceptedNames !== []) {
            $this->createQueryBuilder('s')
                ->update()
                ->set('s.status', ':accepted')
                ->where('s.targetType = :targetType')
                ->andWhere('s.targetId = :targetId')
                ->andWhere('s.status = :pending')
                ->andWhere('s.tagName IN (:names)')
                ->setParameter('accepted', TagSuggestion::STATUS_ACCEPTED)
                ->setParameter('targetType', $targetType)
                ->setParameter('targetId', $targetId)
                ->setParameter('pending', TagSuggestion::STATUS_PENDING)
                ->setParameter('names', $acceptedNames)
                ->getQuery()
                ->execute();
        }

        // Pass 2: whatever is still pending was offered but not kept → dismissed.
        $this->createQueryBuilder('s')
            ->update()
            ->set('s.status', ':dismissed')
            ->where('s.targetType = :targetType')
            ->andWhere('s.targetId = :targetId')
            ->andWhere('s.status = :pending')
            ->setParameter('dismissed', TagSuggestion::STATUS_DISMISSED)
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId)
            ->setParameter('pending', TagSuggestion::STATUS_PENDING)
            ->getQuery()
            ->execute();
    }

    /**
     * Remove every suggestion (any target, any status, any source) carrying a given tag name.
     * Called when a name is blacklisted for the AI, so an already-surfaced suggestion disappears
     * from the validation queue and edit forms immediately — it must never remonter.
     */
    public function deleteByTagName(string $tagName): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.tagName = :tagName')
            ->setParameter('tagName', $tagName)
            ->getQuery()
            ->execute();
    }

    /**
     * Names a human has already decided on for this target — accepted or dismissed — across ALL
     * sources. A re-run skips these so a tag the user kept or rejected is never re-proposed,
     * whatever source (wd/knn) surfaces it next: one decision holds for every source.
     *
     * @return string[]
     */
    public function decidedTagNamesForTarget(string $targetType, string $targetId): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.tagName')
            ->where('s.targetType = :targetType')
            ->andWhere('s.targetId = :targetId')
            ->andWhere('s.status IN (:decided)')
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId)
            ->setParameter('decided', [TagSuggestion::STATUS_ACCEPTED, TagSuggestion::STATUS_DISMISSED])
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('strval', $rows);
    }

    /**
     * Best-known category for a suggested tag name, so accepting a suggestion keeps
     * its type (rating/character/copyright/…) instead of defaulting to general. WD
     * assigns a deterministic category per name, so the highest-scoring suggestion
     * carrying a non-null category is a safe answer regardless of target.
     */
    public function findCategoryForName(string $name): ?TagCategory
    {
        $suggestion = $this->createQueryBuilder('s')
            ->where('s.tagName = :name')
            ->andWhere('s.category IS NOT NULL')
            ->setParameter('name', $name)
            ->orderBy('s.score', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $suggestion?->getCategory();
    }
}

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
     * Names a target already has a suggestion for (any status) from a given source.
     * Used to keep re-runs additive: a name the user already accepted or dismissed
     * must not be re-surfaced as a new pending suggestion.
     *
     * @return string[]
     */
    public function existingTagNamesForTarget(string $targetType, string $targetId, string $source): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.tagName')
            ->where('s.targetType = :targetType')
            ->andWhere('s.targetId = :targetId')
            ->andWhere('s.source = :source')
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId)
            ->setParameter('source', $source)
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

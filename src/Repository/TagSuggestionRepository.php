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

    public function findForTarget(string $targetType, string $targetId): array
    {
        return $this->findBy(
            ['targetType' => $targetType, 'targetId' => $targetId],
            ['score' => 'DESC'],
        );
    }

    /**
     * Remove a target's still-pending suggestions for a source so a re-run can replace them;
     * accepted/dismissed rows are left untouched.
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
     * Resolve a target's pending suggestions after human validation: kept names → ACCEPTED, the
     * rest → DISMISSED. Both statuses are terminal, so the names never re-surface on a re-run.
     * Bulk UPDATEs — run it when no matching suggestions are held in the UoW.
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
     * Point every suggestion carrying $from at $to, keeping auto-tagging in sync when a tag is
     * renamed. `tag_name` is a free string (no FK to Tag), so a rename would otherwise leave stale
     * suggestions that recreate the old-named tag on acceptance. All statuses are repointed so the
     * "already decided" history (see decidedTagNamesForTarget) follows the tag too. A target/source
     * already owning a $to row would break the unique constraint, so its stale $from row is dropped
     * rather than renamed. Returns the number of rows renamed. Bulk DELETE/UPDATE — run it when no
     * matching suggestions are held in the UoW.
     */
    public function renameTagName(string $from, string $to): int
    {
        if ($from === $to) {
            return 0;
        }

        // Drop $from rows that would collide with an existing $to row for the same target+source.
        $collision = $this->createQueryBuilder('c')
            ->select('1')
            ->andWhere('c.tagName = :to')
            ->andWhere('c.targetType = s.targetType')
            ->andWhere('c.targetId = s.targetId')
            ->andWhere('c.source = s.source')
            ->getDQL();

        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.tagName = :from')
            ->andWhere(sprintf('EXISTS (%s)', $collision))
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->execute();

        return (int) $this->createQueryBuilder('s')
            ->update()
            ->set('s.tagName', ':to')
            ->where('s.tagName = :from')
            ->setParameter('to', $to)
            ->setParameter('from', $from)
            ->getQuery()
            ->execute();
    }

    /**
     * Number of suggestions carrying a given tag name (any target/status/source). Lets --dry-run
     * report the suggestion rows a rename would touch without writing anything.
     */
    public function countByTagName(string $name): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tagName = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Remove every suggestion carrying a given tag name (any target/status/source). Called when a
     * name is blacklisted so an already-surfaced suggestion disappears from the UI immediately.
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
     * Names a human has already decided on (accepted or dismissed) for this target, across all
     * sources. A re-run skips these, so one decision holds whichever source (wd/ram) resurfaces it.
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
     * The source of a model that has been seen emitting this name (any target/status), or null when
     * no model ever has — i.e. the name is the user's own. Ordered so the answer is stable when both
     * taggers know the name.
     */
    public function modelSourceForName(string $name): ?string
    {
        $row = $this->createQueryBuilder('s')
            ->select('s.source')
            ->where('s.tagName = :name')
            ->setParameter('name', $name)
            ->orderBy('s.source', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['source'] ?? null;
    }

    /**
     * Best-known category for a suggested tag name, so accepting a suggestion keeps its type
     * instead of defaulting to general. WD assigns a deterministic category per name, so the
     * highest-scoring row with a non-null category is a safe answer regardless of target.
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

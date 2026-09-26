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

    public function resolvePendingForTarget(string $targetType, string $targetId, array $acceptedNames): void
    {
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

    public function deleteByTagName(string $tagName): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.tagName = :tagName')
            ->setParameter('tagName', $tagName)
            ->getQuery()
            ->execute();
    }

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

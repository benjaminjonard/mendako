<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Board::class);
    }

    public function findWithoutThumbnailIterable(): iterable
    {
        return $this->createQueryBuilder('b')
            ->where('b.thumbnailPath IS NULL')
            ->andWhere('b.thumbnail IS NOT NULL')
            ->getQuery()
            ->toIterable();
    }

    public function findWithCoverIterable(): iterable
    {
        return $this->createQueryBuilder('b')
            ->where('b.thumbnail IS NOT NULL')
            ->getQuery()
            ->toIterable();
    }

    public function countWithoutThumbnail(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.thumbnailPath IS NULL')
            ->andWhere('b.thumbnail IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countWithCover(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.thumbnail IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function thumbnailPaths(): array
    {
        return array_map('strval', $this->createQueryBuilder('b')
            ->select('b.thumbnailPath')
            ->where('b.thumbnailPath IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function getPostCounters(): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b.id, COUNT(posts) AS counter')
            ->leftJoin('b.posts', 'posts')
            ->groupBy('b.id')
        ;

        $counters = [];
        foreach ($qb->getQuery()->getArrayResult() as $result) {
            $counters[$result['id']] = $result['counter'];
        }

        return $counters;
    }
}

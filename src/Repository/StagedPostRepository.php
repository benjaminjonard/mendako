<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StagedPost;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StagedPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StagedPost::class);
    }

    public function findWithoutThumbnailIterable(): iterable
    {
        return $this->createQueryBuilder('s')
            ->where('s.thumbnailPath IS NULL')
            ->andWhere('s.path IS NOT NULL')
            ->getQuery()
            ->toIterable();
    }

    public function findAllIterable(): iterable
    {
        return $this->createQueryBuilder('s')
            ->where('s.path IS NOT NULL')
            ->getQuery()
            ->toIterable();
    }

    public function countWithoutThumbnail(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.thumbnailPath IS NULL')
            ->andWhere('s.path IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function thumbnailPaths(): array
    {
        return array_map('strval', $this->createQueryBuilder('s')
            ->select('s.thumbnailPath')
            ->where('s.thumbnailPath IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function findAllForUser(User $user): array
    {
        return $this
            ->createQueryBuilder('staged')
            ->where('staged.uploadedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('staged.createdAt', \Doctrine\Common\Collections\Criteria::DESC)
            ->getQuery()
            ->getResult();
    }
}

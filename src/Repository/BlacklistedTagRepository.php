<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BlacklistedTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BlacklistedTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlacklistedTag::class);
    }

    /**
     * Every blacklisted name, for the O(1) skip lookup in the suggestion pipeline.
     */
    public function allNames(): array
    {
        return array_map('strval', $this->createQueryBuilder('b')
            ->select('b.name')
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}

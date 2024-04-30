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

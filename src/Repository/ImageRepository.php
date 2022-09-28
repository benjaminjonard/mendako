<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\Image;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Image::class);
    }

    public function filterByTags(Board $board, array $tags, $page): array
    {
        $qb = $this
            ->createQueryBuilder('image')
            ->where('image.board = :board')
            ->orderBy('image.createdAt')
            ->setFirstResult(($page - 1) * 20)
            ->setMaxResults(20)
            ->setParameter('board', $board)
        ;

        if (!empty($tags)) {
            $qb
                ->join('image.tags', 'tag', 'WITH', 'tag.name in (:tags)')
                ->setParameter('tags', $tags)
            ;
        }

        return $qb->getQuery()->getResult();
    }

    public function countFilterByTags(Board $board, array $tags): int
    {
        $qb = $this
            ->createQueryBuilder('image')
            ->select('COUNT(DISTINCT image.id)')
            ->where('image.board = :board')
            ->setParameter('board', $board)
        ;

        if (!empty($tags)) {
            $qb
                ->join('image.tags', 'tag', 'WITH', 'tag.name in (:tags)')
                ->setParameter('tags', $tags)
            ;
        }

        return $qb->getQuery()->getSingleScalarResult();
    }
}

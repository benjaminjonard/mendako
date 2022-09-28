<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    public function filterByTags(Board $board, array $tags, $page): array
    {
        $qb = $this
            ->createQueryBuilder('post')
            ->where('post.board = :board')
            ->orderBy('post.createdAt')
            ->setFirstResult(($page - 1) * 20)
            ->setMaxResults(20)
            ->setParameter('board', $board)
        ;

        if (!empty($tags)) {
            $qb
                ->join('post.tags', 'tag', 'WITH', 'tag.name in (:tags)')
                ->setParameter('tags', $tags)
            ;
        }

        return $qb->getQuery()->getResult();
    }

    public function countFilterByTags(Board $board, array $tags): int
    {
        $qb = $this
            ->createQueryBuilder('post')
            ->select('COUNT(DISTINCT post.id)')
            ->where('post.board = :board')
            ->setParameter('board', $board)
        ;

        if (!empty($tags)) {
            $qb
                ->join('post.tags', 'tag', 'WITH', 'tag.name in (:tags)')
                ->setParameter('tags', $tags)
            ;
        }

        return $qb->getQuery()->getSingleScalarResult();
    }
}

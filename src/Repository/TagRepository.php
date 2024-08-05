<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\Post;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;

class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    public function findWithCounters(): array
    {
        $countQuery = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT i2.id)')
            ->from(Tag::class, 't2')
            ->join('t2.posts', 'i2')
            ->where('t2 = t')
            ->getDQL()
        ;

        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select("t.id, t.name, t.category, t.suggested, ({$countQuery}) AS counter")
            ->from(Tag::class, 't')
            ->orderBy('t.createdAt', \Doctrine\Common\Collections\Criteria::DESC)
        ;

        return $qb->getQuery()->getArrayResult();
    }

    public function findForPosts(Board $board, array $posts): array
    {
        $countQuery = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT i2.id)')
            ->from(Tag::class, 't2')
            ->join('t2.posts', 'i2', 'WITH', 'i2.board = :board')
            ->where('t2 = t')
            ->getDQL()
        ;

        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->distinct()
            ->select("t.id, t.name, t.category, ({$countQuery}) AS counter")
            ->from(Tag::class, 't')
            ->join('t.posts', 'i', 'WITH', 'i IN (:posts)')
            ->setParameter('posts', $posts)
            ->setParameter('board', $board->getId())
        ;

        $results = $qb->getQuery()->getArrayResult();

        foreach ($results as &$result) {
            $result['category'] = $result['category']->value;
        }

        return $results;
    }

    public function findByIdForInfiniteScroll(Board $board, array $ids): array
    {
        $countQuery = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT i2.id)')
            ->from(Tag::class, 't2')
            ->join('t2.posts', 'i2', 'WITH', 'i2.board = :board')
            ->where('t2 = t')
            ->getDQL()
        ;

        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->distinct()
            ->select("t.id, t.name, t.category, ({$countQuery}) AS counter")
            ->from(Tag::class, 't')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->setParameter('board', $board->getId())
        ;

        $results = $qb->getQuery()->getArrayResult();

        foreach ($results as &$result) {
            $result['category'] = $result['category']->value;
        }

        return $results;
    }

    public function findLike(string $query)
    {
        return $this
            ->createQueryBuilder('tag')
            ->addSelect('(CASE WHEN LOWER(tag.name) LIKE LOWER(:startWith) THEN 0 ELSE 1 END) AS HIDDEN startWithOrder')
            ->andWhere('LOWER(tag.name) LIKE LOWER(:query)')
            ->orderBy('startWithOrder', Criteria::ASC) // Order tags starting with the search term first
            ->addOrderBy('LOWER(tag.name)', Criteria::ASC) // Then order other matching tags alphabetically
            ->setParameter('query', '%'.trim($query).'%')
            ->setParameter('startWith', trim($query).'%')
            ->setMaxResults(15)
            ->getQuery()
            ->getResult()
        ;
    }
}

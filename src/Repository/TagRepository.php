<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Image;
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
        $countQuery = $this->_em
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT i2.id)')
            ->from(Tag::class, 't2')
            ->join('t2.images', 'i2')
            ->where('t2 = t')
            ->getDQL()
        ;

        $qb = $this->_em
            ->createQueryBuilder()
            ->select("t.id, t.name, t.category, ($countQuery) AS counter")
            ->from(Tag::class, 't')
            ->orderBy('t.createdAt', 'DESC')
        ;

        $results = $qb->getQuery()->getArrayResult();

        return $results;
    }

    public function findForImage(Image $image): array
    {
        $countQuery = $this->_em
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT i2.id)')
            ->from(Tag::class, 't2')
            ->join('t2.images', 'i2', 'WITH', 'i2.board = :board')
            ->where('t2 = t')
            ->getDQL()
        ;

        $qb = $this->_em
            ->createQueryBuilder()
            ->select("t.id, t.name, t.category, ($countQuery) AS counter")
            ->from(Tag::class, 't')
            ->join('t.images', 'i', 'WITH', 'i = :image')
            ->orderBy('t.name', 'ASC')
            ->setParameter('image', $image->getId())
            ->setParameter('board', $image->getBoard()->getId())
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
            ->setMaxResults(5)
            ->getQuery()
            ->getResult()
        ;
    }
}

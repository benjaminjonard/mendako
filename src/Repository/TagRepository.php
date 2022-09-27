<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Image;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
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
}

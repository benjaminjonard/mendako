<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    public function filterByTags(Board $board, string $tags, $page, int $postPerPage): array
    {
        $postPerPage = min($postPerPage, 200);

        $tags = explode(' ', $tags);
        $tags = array_filter($tags);

        $qb = $this
            ->createQueryBuilder('post')
            ->where('post.board = :board')
            ->orderBy('post.createdAt', Order::Descending->value)
            ->setFirstResult(($page - 1) * $postPerPage)
            ->setMaxResults($postPerPage)
            ->setParameter('board', $board)
        ;

        if ($tags !== []) {
            $qb
                ->join('post.tags', 'tag', 'WITH', 'tag.name in (:tags)')
                ->groupBy('post.id')
                ->having('COUNT(DISTINCT tag.id) >= :count')
                ->setParameter('tags', $tags)
                ->setParameter('count', \count($tags))
            ;
        }

        return $qb->getQuery()->getResult();
    }

    public function countFilterByTags(Board $board, string $tags): int
    {
        $tags = explode(' ', $tags);
        $tags = array_filter($tags);

        $qb = $this
            ->createQueryBuilder('post')
            ->distinct()
            ->select('COUNT(DISTINCT post.id) as count')
            ->where('post.board = :board')
            ->setParameter('board', $board)
        ;

        if ($tags !== []) {
            $qb
                ->join('post.tags', 'tag', 'WITH', 'tag.name in (:tags)')
                ->groupBy('post.id')
                ->having('COUNT(DISTINCT tag.id) >= :count')
                ->setParameter('tags', $tags)
                ->setParameter('count', \count($tags))
            ;
        }

        $result = $qb->getQuery()->getScalarResult();

        return $result === [] ? 0 : $result[0]['count'];
    }

    public function findSimilarByVector(string $vector, int $maxHamming = 10, int $limit = 3): array {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                post.id,
                post.path,
                post.thumbnail_path,
                post.mimetype,
                post.created_at AS created_at,
                (1 - power(post.vector <-> :vector, 2) / 64) * 100 as distance,
                board.id AS board_id,
                board.slug AS board_slug
            FROM men_post post
            LEFT JOIN men_board board ON post.board_id = board.id
            WHERE post.vector IS NOT NULL
            AND power(post.vector <-> :vector, 2) <= :max_hamming
            ORDER BY post.vector <-> :vector
            LIMIT :limit
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':vector', $vector);
        $stmt->bindValue(':max_hamming', $maxHamming, ParameterType::INTEGER);
        $stmt->bindValue(':limit', $limit, ParameterType::INTEGER);

        return $stmt->executeQuery()->fetchAllAssociative();
    }

    public function findWithoutVectorIterable(): iterable
    {
        return $this->createQueryBuilder('p')
            ->where('p.vector IS NULL')
            ->getQuery()
            ->toIterable();
    }

    public function findWithoutThumbnailIterable(): iterable
    {
        return $this->createQueryBuilder('p')
            ->where('p.thumbnailPath IS NULL')
            ->andWhere('p.path IS NOT NULL')
            ->getQuery()
            ->toIterable();
    }

    public function countWithoutThumbnail(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.thumbnailPath IS NULL')
            ->andWhere('p.path IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function thumbnailPaths(): array
    {
        return array_map('strval', $this->createQueryBuilder('p')
            ->select('p.thumbnailPath')
            ->where('p.thumbnailPath IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function countWithoutVector(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.vector IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findAllIterable(): iterable
    {
        return $this->createQueryBuilder('p')->getQuery()->toIterable();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }
}

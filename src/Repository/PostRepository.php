<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\Post;
use App\Entity\Tag;
use App\Enum\TagCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;

class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * Returns posts that have no "real" tag, i.e. no tag whose category is anything other than meta.
     * A post tagged only with meta tags (audio, video, animated, ...) — or with no tag at all — counts as untagged.
     *
     * @return Post[]
     */
    public function findWithoutTags(): array
    {
        $realTagQuery = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('1')
            ->from(Tag::class, 'realTag')
            ->join('realTag.posts', 'taggedPost')
            ->where('taggedPost = post')
            ->andWhere('realTag.category IS NULL OR realTag.category != :metaCategory')
            ->getDQL()
        ;

        return $this
            ->createQueryBuilder('post')
            ->where(sprintf('NOT EXISTS (%s)', $realTagQuery))
            ->orderBy('post.createdAt', Criteria::DESC)
            ->setParameter('metaCategory', TagCategory::META->value)
            ->getQuery()
            ->getResult()
        ;
    }

    public function filterByTags(Board $board, string $tags, $page, int $postPerPage): array
    {
        $postPerPage = min($postPerPage, 200);

        $tags = explode(' ', $tags);
        $tags = array_filter($tags);

        $qb = $this
            ->createQueryBuilder('post')
            ->where('post.board = :board')
            ->orderBy('post.createdAt', \Doctrine\Common\Collections\Criteria::DESC)
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

    public function findSimilarByVector(string $vector, float $maxDistance = 0.3, int $limit = 3): array {
        $conn = $this->getEntityManager()->getConnection();

        // Use L2 distance operator <->
        // Lower distance = more similar
        $sql = "
            SELECT 
                post.id,
                post.path,
                post.mimetype,
                post.created_at AS created_at,
                (1 - (post.vector <-> :vector)) * 100 as distance,
                board.id AS board_id,
                board.slug AS board_slug
            FROM men_post post
            LEFT JOIN men_board board ON post.board_id = board.id 
            WHERE post.vector IS NOT NULL
            AND post.vector <-> :vector < :max_distance
            ORDER BY post.vector <-> :vector
            LIMIT :limit
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':vector', $vector);
        $stmt->bindValue(':max_distance', $maxDistance);
        $stmt->bindValue(':limit', $limit);

        return $stmt->executeQuery()->fetchAllAssociative();
    }
}

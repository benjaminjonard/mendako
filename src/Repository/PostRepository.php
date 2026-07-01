<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\Post;
use App\Entity\TagSuggestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
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

    /**
     * kNN over the semantic CLIP embedding: the confirmed tags of the nearest
     * already-tagged Posts (same CLIP model), for learned tag suggestions.
     *
     * Returns rows {similarity, name, category} — one per (neighbour, tag) — for the
     * `k` nearest neighbours within the similarity floor. Read-only; never writes.
     *
     * @return array<int, array{similarity: float, name: string, category: ?string}>
     */
    /**
     * Stream every post (for `--all` retroactive tagging). `toIterable()` so a large
     * back-catalogue isn't fully hydrated at once.
     *
     * @return iterable<Post>
     */
    public function findAllIterable(): iterable
    {
        return $this->createQueryBuilder('p')->getQuery()->toIterable();
    }

    /**
     * Stream posts that have no automatic tagging suggestion yet (never processed) — the default
     * retroactive set. `men_tag_suggestion` is polymorphic (no FK), so this is a
     * correlated NOT EXISTS on (target_type='post', target_id = post id).
     *
     * @return iterable<Post>
     */
    public function findWithoutSuggestionsIterable(): iterable
    {
        $qb = $this->createQueryBuilder('p');
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(TagSuggestion::class, 's')
            ->where('s.targetType = :targetType')
            ->andWhere('s.targetId = p.id');

        return $qb
            ->where($qb->expr()->not($qb->expr()->exists($sub->getDQL())))
            ->setParameter('targetType', 'post')
            ->getQuery()
            ->toIterable();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Number of posts with no automatic tagging suggestion yet (the un-processed backlog) — the COUNT
     * form of findWithoutSuggestionsIterable().
     */
    public function countWithoutSuggestions(): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(TagSuggestion::class, 's')
            ->where('s.targetType = :targetType')
            ->andWhere('s.targetId = p.id');

        return (int) $qb
            ->where($qb->expr()->not($qb->expr()->exists($sub->getDQL())))
            ->setParameter('targetType', 'post')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findNearestConfirmedTagsByClipVector(
        string $clipVector,
        string $clipModelId,
        ?string $excludePostId,
        int $k,
        float $minSimilarity,
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        // Cosine distance operator <=> (the clip_vector hnsw index uses vector_cosine_ops);
        // similarity = 1 - distance. Filter to the producing model so cross-model
        // distances are never mixed, and only consider Posts that carry a confirmed tag.
        $sql = "
            WITH neighbours AS (
                SELECT post.id, (1 - (post.clip_vector <=> :vector)) AS similarity
                FROM men_post post
                WHERE post.clip_vector IS NOT NULL
                  AND post.clip_model_id = :model
                  AND post.id != :self
                  AND (post.clip_vector <=> :vector) <= :max_distance
                  AND EXISTS (SELECT 1 FROM men_post_tag pt WHERE pt.post_id = post.id)
                ORDER BY post.clip_vector <=> :vector
                LIMIT :k
            )
            SELECT neighbours.similarity AS similarity, tag.name AS name, tag.category AS category
            FROM neighbours
            JOIN men_post_tag pt ON pt.post_id = neighbours.id
            JOIN men_tag tag ON tag.id = pt.tag_id
            -- 'meta' tags (animated/video/gif/with_sound) describe the file format,
            -- not visual content, so they must not propagate by image similarity.
            WHERE tag.category IS DISTINCT FROM 'meta'
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':vector', $clipVector);
        $stmt->bindValue(':model', $clipModelId);
        // null exclude → a sentinel that matches no uuid, so nothing is excluded.
        $stmt->bindValue(':self', $excludePostId ?? '');
        $stmt->bindValue(':max_distance', 1 - $minSimilarity);
        $stmt->bindValue(':k', $k, ParameterType::INTEGER);

        return $stmt->executeQuery()->fetchAllAssociative();
    }
}

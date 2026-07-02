<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\Embedding;
use App\Entity\Post;
use App\Entity\TagSuggestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
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

    /**
     * Nearest posts by perceptual pHash — near-duplicate / "similar posts" detection.
     *
     * The `vector` column holds a 64-bit binary pHash (see PostVectorService), so the pgvector L2
     * operator `<->` equals sqrt(Hamming distance). We therefore threshold and rank on Hamming: two
     * images match when at most `$maxHamming` of the 64 bits differ (10 ≈ the usual pHash "same image"
     * cut-off, tolerant to re-encode/scale/minor edits). The `<->` ORDER BY is served by the HNSW
     * `vector_l2_ops` index. `distance` is returned as a 0-100 similarity percentage (100 = identical).
     */
    public function findSimilarByVector(string $vector, int $maxHamming = 10, int $limit = 3): array {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                post.id,
                post.path,
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
        $stmt->bindValue(':max_hamming', $maxHamming);
        $stmt->bindValue(':limit', $limit);

        return $stmt->executeQuery()->fetchAllAssociative();
    }

    /**
     * Stream posts with no perceptual duplicate-detection vector yet — the default "recompute
     * missing" set for the admin backfill job. `toIterable()` so a large back-catalogue isn't
     * fully hydrated at once.
     *
     * @return iterable<Post>
     */
    public function findWithoutVectorIterable(): iterable
    {
        return $this->createQueryBuilder('p')
            ->where('p.vector IS NULL')
            ->getQuery()
            ->toIterable();
    }

    /**
     * Number of posts still missing a duplicate-detection vector (COUNT form of the above).
     */
    public function countWithoutVector(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.vector IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * kNN over the semantic embedding: the confirmed tags of the nearest
     * already-tagged Posts (same embedding model), for learned tag suggestions.
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

    /**
     * Pick one random post that still has at least one pending automatic-tagging suggestion —
     * the working set for the Tag validation queue. Returns null when the queue is empty.
     * Native SQL because DQL has no portable ORDER BY RANDOM(); we only select the id and
     * re-hydrate the managed entity through the ORM.
     */
    public function findRandomWithPendingSuggestions(): ?Post
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT post.id
            FROM men_post post
            WHERE EXISTS (
                SELECT 1
                FROM men_tag_suggestion s
                WHERE s.target_type = 'post'
                AND s.target_id = post.id
                AND s.status = :status
            )
            ORDER BY RANDOM()
            LIMIT 1
        ";

        $id = $conn->executeQuery($sql, ['status' => TagSuggestion::STATUS_PENDING])->fetchOne();

        return $id === false ? null : $this->find($id);
    }

    /**
     * How many distinct posts still have at least one pending suggestion — the size of the Tag
     * validation queue (COUNT form of findRandomWithPendingSuggestions()), used for the menu badge.
     */
    public function countPostsWithPendingSuggestions(): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(TagSuggestion::class, 's')
            ->where('s.targetType = :targetType')
            ->andWhere('s.targetId = p.id')
            ->andWhere('s.status = :status');

        return (int) $qb
            ->where($qb->expr()->exists($sub->getDQL()))
            ->setParameter('targetType', 'post')
            ->setParameter('status', TagSuggestion::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
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

    /**
     * Stream posts that have no embedding row yet — the default "embed missing" set that fills
     * the kNN/classifier pool. `toIterable()` so a large back-catalogue isn't fully hydrated.
     *
     * @return iterable<Post>
     */
    public function findWithoutEmbeddingIterable(): iterable
    {
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(Embedding::class, 'e')
            ->where("e.targetType = 'post'")
            ->andWhere('e.targetId = p.id');

        $qb = $this->createQueryBuilder('p');

        return $qb
            ->where($qb->expr()->not($qb->expr()->exists($sub->getDQL())))
            ->getQuery()
            ->toIterable();
    }

    /**
     * Number of posts still missing an embedding (COUNT form of findWithoutEmbeddingIterable()).
     */
    public function countWithoutEmbedding(): int
    {
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(Embedding::class, 'e')
            ->where("e.targetType = 'post'")
            ->andWhere('e.targetId = p.id');

        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');

        return (int) $qb
            ->where($qb->expr()->not($qb->expr()->exists($sub->getDQL())))
            ->getQuery()
            ->getSingleScalarResult();
    }
}

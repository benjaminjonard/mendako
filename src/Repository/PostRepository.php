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

    /**
     * Normalized names of the tags already applied to a post (via men_post_tag). Auto-tagging
     * uses these to skip re-proposing a tag the post already carries. Returns [] for an unknown id.
     */
    public function appliedTagNamesForPost(string $postId): array
    {
        $rows = $this->createQueryBuilder('post')
            ->select('tag.name')
            ->join('post.tags', 'tag')
            ->where('post.id = :id')
            ->setParameter('id', $postId)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('strval', $rows);
    }

    /**
     * Nearest posts by perceptual pHash for near-duplicate detection. The `vector` column holds a
     * 64-bit binary pHash, so pgvector's L2 `<->` equals sqrt(Hamming distance): results are ranked on
     * Hamming and thresholded at `$maxHamming` bits (10 ≈ the usual pHash "same image" cut-off), served
     * by the HNSW `vector_l2_ops` index. `distance` is a 0-100 similarity percentage.
     */
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

    /**
     * Stream posts with no duplicate-detection vector — the "recompute missing" backfill set.
     */
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

    /**
     * Stream every post (for `--all` retroactive tagging).
     */
    public function findAllIterable(): iterable
    {
        return $this->createQueryBuilder('p')->getQuery()->toIterable();
    }

    /**
     * Stream posts with no tag suggestion yet (never processed) — the default retroactive set.
     * `men_tag_suggestion` is polymorphic (no FK), hence a correlated NOT EXISTS on the target.
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
     * The most recent post with a pending suggestion — the Tag validation queue's working set, or
     * null when empty. Native SQL for symmetry with the sibling queue queries; re-hydrates via find().
     */
    public function findLatestWithPendingSuggestions(): ?Post
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
            ORDER BY post.created_at DESC
            LIMIT 1
        ";

        $id = $conn->executeQuery($sql, ['status' => TagSuggestion::STATUS_PENDING])->fetchOne();

        return $id === false ? null : $this->find($id);
    }

    /**
     * How many distinct posts still have a pending suggestion — the Tag validation queue size (menu badge).
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

    public function countOnBoards(array $slugs): int
    {
        if ($slugs === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->join('p.board', 'b')
            ->where('b.slug IN (:slugs)')
            ->setParameter('slugs', $slugs)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countWithoutSuggestionsOnBoards(array $slugs): int
    {
        if ($slugs === []) {
            return 0;
        }

        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(TagSuggestion::class, 's')
            ->where('s.targetType = :targetType')
            ->andWhere('s.targetId = p.id');

        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');

        return (int) $qb
            ->join('p.board', 'b')
            ->where('b.slug IN (:slugs)')
            ->andWhere($qb->expr()->not($qb->expr()->exists($sub->getDQL())))
            ->setParameter('slugs', $slugs)
            ->setParameter('targetType', 'post')
            ->getQuery()
            ->getSingleScalarResult();
    }

}

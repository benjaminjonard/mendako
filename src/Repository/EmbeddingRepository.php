<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Embedding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class EmbeddingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Embedding::class);
    }

    /**
     * Replace all of a target's embeddings (one row per frame). Atomic drop-and-reinsert, so a
     * re-run never accumulates stale frames.
     */
    public function replaceForTarget(string $targetType, string $targetId, string $modelId, array $vectors): void
    {
        $this->getEntityManager()->wrapInTransaction(function () use ($targetType, $targetId, $modelId, $vectors): void {
            $this->deleteForTarget($targetType, $targetId);
            foreach (array_values($vectors) as $ordinal => $vector) {
                $embedding = (new Embedding())
                    ->setTargetType($targetType)
                    ->setTargetId($targetId)
                    ->setOrdinal($ordinal)
                    ->setEmbeddingModelId($modelId)
                    ->setEmbeddingVector($vector);
                $this->getEntityManager()->persist($embedding);
            }
            $this->getEntityManager()->flush();
        });
    }

    public function deleteForTarget(string $targetType, string $targetId): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.targetType = :type')
            ->andWhere('e.targetId = :id')
            ->setParameter('type', $targetType)
            ->setParameter('id', $targetId)
            ->getQuery()
            ->execute();
    }

    /**
     * Confirmed tags of the already-tagged Posts nearest (by any frame) to the query vector, same
     * encoder, excluding the query target's own frames.
     */
    public function findNearestConfirmedTags(
        string $vector,
        string $modelId,
        ?string $excludeTargetId,
        int $k,
        float $minSimilarity,
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        // Cosine distance operator <=> (the hnsw index uses vector_cosine_ops); similarity = 1 - distance.
        $sql = "
            WITH neighbours AS (
                SELECT emb.target_id AS post_id, (1 - (emb.embedding_vector <=> :vector)) AS similarity
                FROM men_embedding emb
                WHERE emb.target_type = 'post'
                  AND emb.embedding_model_id = :model
                  AND emb.target_id != :self
                  AND (emb.embedding_vector <=> :vector) <= :max_distance
                  AND EXISTS (SELECT 1 FROM men_post_tag pt WHERE pt.post_id = emb.target_id)
                ORDER BY emb.embedding_vector <=> :vector
                LIMIT :k
            )
            SELECT neighbours.similarity AS similarity, tag.name AS name, tag.category AS category
            FROM neighbours
            JOIN men_post_tag pt ON pt.post_id = neighbours.post_id
            JOIN men_tag tag ON tag.id = pt.tag_id
            -- 'meta' tags (animated/video/gif/with_sound) describe the file format,
            -- not visual content, so they must not propagate by image similarity.
            WHERE tag.category IS DISTINCT FROM 'meta'
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':vector', $vector);
        $stmt->bindValue(':model', $modelId);
        // null exclude → a sentinel that matches no uuid, so nothing is excluded.
        $stmt->bindValue(':self', $excludeTargetId ?? '');
        $stmt->bindValue(':max_distance', 1 - $minSimilarity);
        $stmt->bindValue(':k', $k, ParameterType::INTEGER);

        return $stmt->executeQuery()->fetchAllAssociative();
    }
}

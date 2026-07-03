<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\TagSuggestion;
use App\Repository\EmbeddingRepository;

/**
 * Propagates the confirmed tags of the nearest already-tagged Posts (by embedding similarity) onto
 * a freshly-processed item as `knn`-source suggestions. Reads confirmed tags live — no retraining.
 * An item may carry several embeddings (one per video frame); every frame's neighbours are merged.
 * Best-effort + additive: an empty neighbourhood simply produces no learned suggestions.
 */
class KnnSuggestionService
{
    private const int K = 10;
    private const float MIN_SIMILARITY = 0.5;

    public function __construct(
        private readonly EmbeddingRepository $embeddingRepository,
        private readonly SuggestionService $suggestionService,
    ) {
    }

    public function propagate(string $targetType, string $targetId, array $vectors, string $modelId): void
    {
        // A Post target must not be its own neighbour (don't echo its own tags).
        $excludeId = $targetType === 'post' ? $targetId : null;

        // Aggregate by tag name across every frame, keeping the highest neighbour similarity.
        $byName = [];
        foreach ($vectors as $vector) {
            foreach ($this->embeddingRepository->findNearestConfirmedTags($vector, $modelId, $excludeId, self::K, self::MIN_SIMILARITY) as $row) {
                $name = $row['name'];
                $score = (float) $row['similarity'];
                if (!isset($byName[$name]) || $score > $byName[$name]['score']) {
                    $byName[$name] = ['score' => $score, 'category' => $row['category']];
                }
            }
        }

        $tags = [];
        foreach ($byName as $name => $candidate) {
            $tags[] = ['name' => $name, 'category' => $candidate['category'], 'score' => $candidate['score']];
        }

        // Persist as `knn` suggestions — merged/ranked with the `wd` rows by SuggestionService.
        // An empty set clears stale pending `knn` rows and adds nothing (graceful cold start).
        $this->suggestionService->store($targetType, $targetId, ['tags' => $tags, 'rating' => ['label' => null]], TagSuggestion::SOURCE_KNN);
    }
}

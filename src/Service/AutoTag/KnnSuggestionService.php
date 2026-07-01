<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\TagSuggestion;
use App\Repository\EmbeddingRepository;

/**
 * Learned suggestions: propagate the confirmed tags of the nearest already-tagged Posts
 * (by embedding similarity) onto a freshly-processed item as `knn`-source TagSuggestions.
 * Reads confirmed tags live, so a tag confirmed on one item is immediately eligible on future
 * similar items — no retraining step.
 *
 * An item can carry several embeddings (one per video frame); every frame searches for
 * neighbours and the results are merged, so a video matches a neighbour if ANY of its frames
 * resembles ANY of the neighbour's frames.
 *
 * Best-effort + additive: writes only via SuggestionService (men_tag_suggestion); an empty
 * neighbourhood (cold start) simply produces no learned suggestions.
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

    /**
     * @param string[] $vectors the item's embedding vectors (one per frame; one for an image)
     */
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

<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\TagSuggestion;
use App\Repository\PostRepository;

/**
 * Learned suggestions: propagate the confirmed tags of the nearest already-tagged
 * Posts (by CLIP embedding similarity) onto a freshly-processed item as
 * `knn`-source TagSuggestions. Reads confirmed tags live, so a tag confirmed on one
 * item is immediately eligible on future similar items — no retraining step.
 *
 * Best-effort + additive: writes only via SuggestionService (men_tag_suggestion);
 * an empty neighbourhood (cold start) simply produces no learned suggestions.
 */
class KnnSuggestionService
{
    private const int K = 10;
    private const float MIN_SIMILARITY = 0.5;

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly SuggestionService $suggestionService,
    ) {
    }

    public function propagate(string $targetType, string $targetId, string $clipVector, string $clipModelId): void
    {
        // A Post target must not be its own neighbour (don't echo its own tags).
        $excludePostId = $targetType === 'post' ? $targetId : null;

        $rows = $this->postRepository->findNearestConfirmedTagsByClipVector(
            $clipVector,
            $clipModelId,
            $excludePostId,
            self::K,
            self::MIN_SIMILARITY,
        );

        // Aggregate by tag name, keeping the highest neighbour similarity as the score.
        $byName = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            $score = (float) $row['similarity'];
            if (!isset($byName[$name]) || $score > $byName[$name]['score']) {
                $byName[$name] = ['score' => $score, 'category' => $row['category']];
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

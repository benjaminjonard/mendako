<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

/**
 * Merges per-frame `/analyze` results for a multi-frame video into one suggestion set:
 * each WD tag keeps its highest score across frames (a concept present in any frame is a good
 * suggestion) and the rating is the highest-scoring across frames. Embeddings are handled
 * separately (one row per frame), so they are not aggregated here.
 */
class FrameResultAggregator
{
    /**
     * @param array<int, array<string, mixed>> $frameResults
     *
     * @return array<string, mixed>
     */
    public function aggregate(array $frameResults): array
    {
        $tagsByName = [];
        $rating = ['label' => null, 'score' => 0.0];

        foreach ($frameResults as $frame) {
            foreach ($frame['tags'] ?? [] as $tag) {
                $name = $tag['name'] ?? null;
                if ($name === null) {
                    continue;
                }
                $score = (float) ($tag['score'] ?? 0.0);
                // Dedup by name only: the highest-scoring frame's score + category win
                // (a WD model maps a name to one category, so frames don't disagree).
                if (!isset($tagsByName[$name]) || $score > $tagsByName[$name]['score']) {
                    $tagsByName[$name] = ['score' => $score, 'category' => $tag['category'] ?? null];
                }
            }

            $ratingScore = (float) ($frame['rating']['score'] ?? 0.0);
            if (($frame['rating']['label'] ?? null) !== null && $ratingScore > $rating['score']) {
                $rating = ['label' => $frame['rating']['label'], 'score' => $ratingScore];
            }
        }

        $tags = [];
        foreach ($tagsByName as $name => $candidate) {
            $tags[] = ['name' => $name, 'category' => $candidate['category'], 'score' => $candidate['score']];
        }
        usort($tags, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return ['tags' => $tags, 'rating' => $rating];
    }
}

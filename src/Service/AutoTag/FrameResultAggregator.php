<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

class FrameResultAggregator
{
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

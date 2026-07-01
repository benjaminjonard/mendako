<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Service\AutoTag\FrameResultAggregator;
use PHPUnit\Framework\TestCase;

class FrameResultAggregatorTest extends TestCase
{
    public function test_aggregates_max_score_per_tag_and_rating(): void
    {
        $frames = [
            ['tags' => [['name' => 'cat', 'category' => 'general', 'score' => 0.4], ['name' => 'tree', 'category' => 'general', 'score' => 0.7]], 'rating' => ['label' => 'general', 'score' => 0.5]],
            ['tags' => [['name' => 'cat', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => 'sensitive', 'score' => 0.8]],
        ];

        $result = (new FrameResultAggregator())->aggregate($frames, 1);

        // Max score per tag, sorted desc.
        $this->assertSame([
            ['name' => 'cat', 'category' => 'general', 'score' => 0.9],
            ['name' => 'tree', 'category' => 'general', 'score' => 0.7],
        ], $result['tags']);
        // Highest-scoring rating across frames.
        $this->assertSame('sensitive', $result['rating']['label']);
        $this->assertSame(0.8, $result['rating']['score']);
    }

    public function test_carries_embedding_and_zeroshot_from_representative_only(): void
    {
        $frames = [
            ['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]],
            ['tags' => [], 'rating' => ['label' => null, 'score' => 0.0], 'embedding' => [0.1, 0.2], 'embedding_dim' => 2, 'clip_model_id' => 'm1', 'zeroshot' => [['name' => 'fox', 'score' => 0.3]]],
            ['tags' => [], 'rating' => ['label' => null, 'score' => 0.0], 'embedding' => [9.9], 'zeroshot' => [['name' => 'ignored', 'score' => 1.0]]],
        ];

        $result = (new FrameResultAggregator())->aggregate($frames, 1);

        $this->assertSame([0.1, 0.2], $result['embedding']);
        $this->assertSame('m1', $result['clip_model_id']);
        $this->assertSame([['name' => 'fox', 'score' => 0.3]], $result['zeroshot']);
    }

    public function test_single_frame_passes_through(): void
    {
        $frame = ['tags' => [['name' => 'cat', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => 'general', 'score' => 0.5]];

        $result = (new FrameResultAggregator())->aggregate([$frame], 0);

        $this->assertSame($frame['tags'], $result['tags']);
        $this->assertSame('general', $result['rating']['label']);
    }
}

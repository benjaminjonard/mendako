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

        $result = (new FrameResultAggregator())->aggregate($frames);

        // Max score per tag, sorted desc.
        $this->assertSame([
            ['name' => 'cat', 'category' => 'general', 'score' => 0.9],
            ['name' => 'tree', 'category' => 'general', 'score' => 0.7],
        ], $result['tags']);
        // Highest-scoring rating across frames.
        $this->assertSame('sensitive', $result['rating']['label']);
        $this->assertSame(0.8, $result['rating']['score']);
    }

    public function test_single_frame_passes_through(): void
    {
        $frame = ['tags' => [['name' => 'cat', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => 'general', 'score' => 0.5]];

        $result = (new FrameResultAggregator())->aggregate([$frame]);

        $this->assertSame($frame['tags'], $result['tags']);
        $this->assertSame('general', $result['rating']['label']);
    }
}

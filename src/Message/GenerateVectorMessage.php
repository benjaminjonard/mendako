<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asynchronous request to (re)compute + store one post's perceptual duplicate-detection vector.
 * Pure PHP/GD via PostVectorService (no ML call), so it runs regardless of the auto-tag feature.
 * Routed to the deprioritized autotag_batch transport.
 */
final readonly class GenerateVectorMessage
{
    public function __construct(
        public string $targetType, // 'post' (bulk uploads recompute at stage time)
        public string $id,
    ) {
    }
}

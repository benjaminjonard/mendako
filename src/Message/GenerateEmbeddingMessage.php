<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asynchronous request to compute + store one item's embedding (embedding pool) — no tagging, no
 * suggestions, just the visual encoder. Feeds the kNN "learned" suggestions. Routed to the
 * deprioritized autotag_batch transport.
 */
final readonly class GenerateEmbeddingMessage
{
    public function __construct(
        public string $targetType, // 'post' | 'bulk'
        public string $id,
    ) {
    }
}

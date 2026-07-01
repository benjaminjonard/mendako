<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asynchronous request to compute + store the embedding of a single item (embedding pool).
 *
 * Visual-encoder-only: no WD tagging, no suggestions. Feeds the kNN "learned" suggestions and,
 * later, a trained tag classifier. Routed to the deprioritized autotag_batch transport.
 */
final readonly class GenerateEmbeddingMessage
{
    public function __construct(
        public string $targetType, // 'post' | 'staged'
        public string $id,
    ) {
    }
}

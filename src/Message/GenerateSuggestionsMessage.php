<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asynchronous request to generate tag suggestions for an uploaded item. Carries the target
 * identity; the handler is idempotent, so a re-run simply re-processes the same item.
 */
final readonly class GenerateSuggestionsMessage
{
    public function __construct(
        public string $targetType, // 'post' | 'bulk'
        public string $id,
    ) {
    }
}

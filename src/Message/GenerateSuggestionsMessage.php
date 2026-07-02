<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asynchronous request to generate Automatic tagss for an uploaded item.
 *
 * Carries the target identity plus a per-item token (`contentHash`). The token's
 * staleness semantics (how re-runs use it) are defined in Story 2.3 when it is consumed.
 */
final readonly class GenerateSuggestionsMessage
{
    public function __construct(
        public string $targetType, // 'post' | 'bulk'
        public string $id,
        public string $contentHash,
    ) {
    }
}

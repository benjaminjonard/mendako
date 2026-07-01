<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asynchronous request to (re)compute + store the perceptual duplicate-detection vector of a single
 * post. Pure PHP/GD via PostVectorService — no ML service call — so it runs regardless of whether
 * the auto-tag feature is enabled. Routed to the deprioritized autotag_batch transport.
 */
final readonly class GenerateVectorMessage
{
    public function __construct(
        public string $targetType, // 'post' (staged uploads recompute at stage time)
        public string $id,
    ) {
    }
}

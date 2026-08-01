<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Kicks off a bulk thumbnail rebuild; the handler purges unreferenced files then fans out one
 * GenerateThumbnailMessage per target. all=false only covers targets with no thumbnail yet.
 */
final readonly class EnqueueThumbnailBacklogMessage
{
    public function __construct(
        public bool $all = false,
    ) {
    }
}

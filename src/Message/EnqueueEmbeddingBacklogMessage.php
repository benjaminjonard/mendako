<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Kicks off a bulk embedding run: the handler fans out one GenerateEmbeddingMessage per post
 * on the worker, so the admin UI trigger returns immediately even for a large library.
 *
 * all=false embeds only posts still missing an embedding; all=true re-embeds everything.
 */
final readonly class EnqueueEmbeddingBacklogMessage
{
    public function __construct(
        public bool $all = false,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Kicks off a bulk recompute of the perceptual duplicate-detection vector: the handler fans out one
 * GenerateVectorMessage per post on the worker, so the admin UI trigger returns immediately even for
 * a large library.
 *
 * all=false recomputes only posts still missing a vector; all=true recomputes everything (required
 * after the pHash algorithm changes, since the vector is otherwise only built at upload time).
 */
final readonly class EnqueueVectorBacklogMessage
{
    public function __construct(
        public bool $all = false,
    ) {
    }
}

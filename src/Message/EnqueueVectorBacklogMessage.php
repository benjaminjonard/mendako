<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Kicks off a bulk recompute of the perceptual duplicate-detection vector; the handler fans out
 * one GenerateVectorMessage per post on the worker. all=false recomputes only posts missing a
 * vector; all=true recomputes everything (needed after a pHash algorithm change, since the vector
 * is otherwise only built at upload time).
 */
final readonly class EnqueueVectorBacklogMessage
{
    public function __construct(
        public bool $all = false,
    ) {
    }
}

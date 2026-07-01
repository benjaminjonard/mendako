<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Kicks off a retroactive tagging run: the handler fans out one
 * GenerateSuggestionsMessage per backlog item on the worker, so the admin UI trigger
 * returns immediately even for a large library.
 */
final readonly class EnqueueBacklogMessage
{
    public function __construct(
        public string $targetType = 'post',
        public bool $all = false,
    ) {
    }
}

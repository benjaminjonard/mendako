<?php

declare(strict_types=1);

namespace App\Message;

final readonly class EnqueueBacklogMessage
{
    public function __construct(
        public bool $all = false,
    ) {
    }
}

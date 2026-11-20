<?php

declare(strict_types=1);

namespace App\Message;

final readonly class EnqueueVectorBacklogMessage
{
    public function __construct(
        public bool $all = false,
    ) {
    }
}

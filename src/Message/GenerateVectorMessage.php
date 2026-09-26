<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateVectorMessage
{
    public function __construct(
        public string $targetType,
        public string $id,
    ) {
    }
}

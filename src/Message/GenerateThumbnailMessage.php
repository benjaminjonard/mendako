<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateThumbnailMessage
{
    public const string TARGET_POST = 'post';
    public const string TARGET_STAGED_POST = 'staged_post';
    public const string TARGET_BOARD = 'board';

    public function __construct(
        public string $targetType,
        public string $id,
    ) {
    }
}

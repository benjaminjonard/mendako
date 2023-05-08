<?php

declare(strict_types=1);

namespace App\Enum;

enum PaginationType: string
{
    case PAGE = 'page';
    case SCROLL = 'scroll';

    public const PAGINATION_TYPES = [
        self::PAGE->value,
        self::SCROLL->value
    ];

    public static function getPaginationTypeLabels(): array
    {
        return [
            self::PAGE->value => 'global.pagination_type.page',
            self::SCROLL->value => 'global.pagination_type.scroll',
        ];
    }
}

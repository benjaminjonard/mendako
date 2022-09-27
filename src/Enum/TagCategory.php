<?php

declare(strict_types=1);

namespace App\Enum;

enum TagCategory: string
{
    case GENERAL = 'general';
    case CHARACTER = 'character';
    case COPYRIGHT = 'copyright';
    case ARTIST = 'artist';
    case META = 'meta';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

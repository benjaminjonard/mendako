<?php

declare(strict_types=1);

namespace App\Enum;

enum Locale: string
{
    case EN = 'en';
    case FR = 'fr';

    public const LOCALES = [
        self::EN->value,
        self::FR->value
    ];

    public static function getLocaleLabels(): array
    {
        return [
            self::EN->value => 'global.locale.en',
            self::FR->value => 'global.locale.fr'
        ];
    }
}

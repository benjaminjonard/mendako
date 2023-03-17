<?php

declare(strict_types=1);

namespace App\Enum;

enum Theme: string
{
    case BROWSER = 'browser';
    case LIGHT = 'light';
    case DARK = 'dark';

    public const THEMES = [
        self::BROWSER->value,
        self::LIGHT->value,
        self::DARK->value,
    ];

    public static function getThemeLabels(): array
    {
        return [
            self::BROWSER->value => 'global.theme.browser',
            self::LIGHT->value => 'global.theme.light',
            self::DARK->value => 'global.theme.dark'
        ];
    }
}

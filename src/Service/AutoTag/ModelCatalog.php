<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

final class ModelCatalog
{
    public const array CATEGORIES = [
        'wd' => [
            'models' => ['mendako-tagger'],
        ],
    ];

    public static function modelsFor(string $category): array
    {
        return self::CATEGORIES[$category]['models'] ?? [];
    }
}

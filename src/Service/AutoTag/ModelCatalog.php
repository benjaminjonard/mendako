<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

/**
 * The models Mendako knows about, one per category, baked into the service image. Kept in sync
 * with the service catalog (ml/app/catalog.py).
 */
final class ModelCatalog
{
    /**
     * category => [models (list of model ids)].
     */
    public const array CATEGORIES = [
        'wd' => [
            'models' => ['wd-eva02-large-tagger-v3'],
        ],
    ];

    public static function modelsFor(string $category): array
    {
        return self::CATEGORIES[$category]['models'] ?? [];
    }
}

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
        // Illustrations: Danbooru tags + a content rating.
        'wd' => [
            'models' => ['wd-eva02-large-tagger-v3'],
        ],
        // Photographs: RAM++ general-purpose tags, no rating.
        'ram' => [
            'models' => ['ram-plus'],
        ],
    ];

    public static function modelsFor(string $category): array
    {
        return self::CATEGORIES[$category]['models'] ?? [];
    }
}

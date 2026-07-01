<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

/**
 * The models Mendako knows about, per category — one model per category, baked into
 * the service image. Kept in sync with the service catalog (ml/app/catalog.py).
 *
 * WD is both the tagger and the embedding encoder (its penultimate feature is the image
 * embedding), so there is a single model and no separate CLIP entry.
 */
final class ModelCatalog
{
    /**
     * category => [models (list of model ids)].
     *
     * @var array<string, array{models: list<string>}>
     */
    public const array CATEGORIES = [
        'wd' => [
            'models' => ['wd-eva02-large-tagger-v3'],
        ],
    ];

    /**
     * @return list<string> model ids
     */
    public static function modelsFor(string $category): array
    {
        return self::CATEGORIES[$category]['models'] ?? [];
    }
}

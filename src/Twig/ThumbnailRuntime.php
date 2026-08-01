<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;
use Twig\Extension\RuntimeExtensionInterface;

class ThumbnailRuntime implements RuntimeExtensionInterface
{
    #[AsTwigFilter('thumbnail')]
    public function thumbnail(?string $thumbnailPath, bool $round = false): string
    {
        if ($thumbnailPath === null || $thumbnailPath === '') {
            return $round ? 'build/images/default-round.png' : 'build/images/default.png';
        }

        return $thumbnailPath;
    }
}

<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\ThumbnailStorage;
use Twig\Attribute\AsTwigFilter;
use Twig\Extension\RuntimeExtensionInterface;

class ThumbnailRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly ThumbnailStorage $thumbnailStorage,
    ) {
    }

    #[AsTwigFilter('thumbnail')]
    public function thumbnail(?string $thumbnailPath, bool $round = false): string
    {
        if ($thumbnailPath === null || $thumbnailPath === '') {
            return $round ? 'build/images/default-round.png' : 'build/images/default.png';
        }

        return $thumbnailPath;
    }

    #[AsTwigFilter('expected_thumbnail')]
    public function expectedThumbnail(?string $uploadPath, ?string $mimetype): ?string
    {
        if ($uploadPath === null || $uploadPath === '') {
            return null;
        }

        return $this->thumbnailStorage->relativePathFor($uploadPath, $mimetype);
    }
}

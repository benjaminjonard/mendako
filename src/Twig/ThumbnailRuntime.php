<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\RuntimeExtensionInterface;

class ThumbnailRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly string $publicPath
    ) {}

    public function thumbnail(?string $imagePath, int $width, bool $round = false): string
    {
        $fullImagePath = $this->publicPath . '/' . $imagePath;
        if ($imagePath === null || !file_exists($fullImagePath)) {
            return $round ? 'build/images/default-round.png' : 'build/images/default.png';
        }

        return "upload.php?width=$width&path=$imagePath";
    }
}

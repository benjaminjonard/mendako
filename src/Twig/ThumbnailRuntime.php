<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\RuntimeExtensionInterface;

class ThumbnailRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly string $publicPath
    ) {}

    public function thumbnail(?string $path, int $width, bool $round = false): string
    {
        $fullImagePath = $this->publicPath . '/' . $path;
        if ($path === null || !file_exists($fullImagePath)) {
            return $round ? 'build/images/default-round.png' : 'build/images/default.png';
        }

        return "upload.php?width={$width}&path={$path}";
    }
}

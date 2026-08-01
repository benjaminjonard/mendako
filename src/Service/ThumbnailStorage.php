<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Names are never reused across regenerations: thumbnails are served straight off disk with a long
 * cache lifetime, so overwriting a path in place would leave stale images in browser caches.
 */
class ThumbnailStorage
{
    public const int POST_WIDTH = 360;
    public const int BOARD_WIDTH = 420;

    private const array SUPPORTED_FORMATS = ['jpeg', 'png', 'webp', 'avif'];
    private const string FALLBACK_FORMAT = 'jpeg';

    public function __construct(
        private readonly RandomStringGenerator $randomStringGenerator,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath,
        #[Autowire('%env(default::APP_THUMBNAILS_FORMAT)%')] private readonly ?string $format = null,
    ) {
    }

    public function relativePathFor(string $uploadPath, ?string $mimetype): string
    {
        $directory = str_starts_with($uploadPath, 'uploads/')
            ? 'thumbnails/'.substr(\dirname($uploadPath), \strlen('uploads/'))
            : 'thumbnails/'.\dirname($uploadPath);

        return rtrim($directory, '/').'/'.pathinfo($uploadPath, PATHINFO_FILENAME).'.'.$this->extensionFor($mimetype);
    }

    public function relativePathForBoard(string $boardId, ?string $mimetype): string
    {
        return 'thumbnails/boards/'.$boardId.'/'.$this->randomStringGenerator->generate(20).'.'.$this->extensionFor($mimetype);
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->publicPath.'/'.$relativePath;
    }

    public function remove(?string $relativePath): void
    {
        if ($relativePath !== null && $relativePath !== '') {
            @unlink($this->absolutePath($relativePath));
        }
    }

    /**
     * @param string[] $referencedPaths every thumbnail path currently stored in the database
     */
    public function purgeUnreferenced(array $referencedPaths): int
    {
        $root = $this->absolutePath('thumbnails');
        if (!is_dir($root)) {
            return 0;
        }

        $referenced = array_flip(array_map(
            fn (string $path): string => $this->absolutePath($path),
            $referencedPaths,
        ));

        $purged = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());

                continue;
            }
            if (str_starts_with($file->getFilename(), '.')) {
                continue;
            }
            if (!isset($referenced[$file->getPathname()])) {
                @unlink($file->getPathname());
                ++$purged;
            }
        }

        return $purged;
    }

    /**
     * Falls back to jpeg for videos and SVGs, which have no usable image extension of their own.
     */
    public function extensionFor(?string $mimetype): string
    {
        $configured = strtolower(trim((string) $this->format));
        if (in_array($configured, self::SUPPORTED_FORMATS, true)) {
            return $configured;
        }

        $sourceFormat = str_replace('image/', '', (string) $mimetype);

        return in_array($sourceFormat, self::SUPPORTED_FORMATS, true) ? $sourceFormat : self::FALLBACK_FORMAT;
    }
}

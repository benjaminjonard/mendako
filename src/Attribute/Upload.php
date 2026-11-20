<?php

declare(strict_types=1);

namespace App\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Upload
{
    public function __construct(
        private readonly string $path,
        private readonly ?string $thumbnailPath = null,
    ) {
    }

    public static function fromReflectionAttribute(\ReflectionAttribute $reflectionAttribute): self
    {
        $arguments = $reflectionAttribute->getArguments();

        return new self(
            $arguments['path'] ?? null,
            $arguments['thumbnailPath'] ?? null,
        );
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }
}

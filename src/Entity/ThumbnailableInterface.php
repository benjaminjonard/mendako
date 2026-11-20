<?php

declare(strict_types=1);

namespace App\Entity;

interface ThumbnailableInterface
{
    public function getThumbnailPath(): ?string;

    public function setThumbnailPath(?string $thumbnailPath): self;
}

<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The path is null until generation has run, and stays null when it fails — never an error,
 * always "show the default image".
 */
interface ThumbnailableInterface
{
    public function getThumbnailPath(): ?string;

    public function setThumbnailPath(?string $thumbnailPath): self;
}

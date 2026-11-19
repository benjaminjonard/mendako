<?php

declare(strict_types=1);

namespace App\Entity;

interface UploadableInterface
{
    public function getUploadRelativeDirectory(): string;
}

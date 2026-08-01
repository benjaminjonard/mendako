<?php

declare(strict_types=1);

namespace App\Entity;

interface UploadableInterface
{
    /**
     * Relative directory (under public/) where the uploaded file must be stored,
     * WITHOUT a trailing slash, e.g. "uploads/boards/{id}" or "uploads/bulk-upload".
     */
    public function getUploadRelativeDirectory(): string;
}

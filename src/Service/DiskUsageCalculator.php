<?php

declare(strict_types=1);

namespace App\Service;

class DiskUsageCalculator
{
    public function getFolderSize(string $path): float
    {
        $size = 0;

        if (!is_dir($path)) {
            return $size;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }
}

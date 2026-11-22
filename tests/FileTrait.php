<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

trait FileTrait
{
    protected function createFile(string $type = 'png', int $width = 1200, int $height = 800): UploadedFile
    {
        $name = uniqid().'.'.$type;
        $path = '/tmp/'.$name;

        match ($type) {
            'png', 'jpg', 'webp', 'avif', 'gif' => $this->createImage($path, $type, $width, $height),
            'mp4' => new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', "testsrc=size={$width}x{$height}:rate=10:duration=3", '-pix_fmt', 'yuv420p', $path])->mustRun(),
            'txt' => file_put_contents($path, 'not an image'),
        };

        return new UploadedFile($path, $name, test: true);
    }

    private function createImage(string $path, string $type, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);

        match ($type) {
            'png' => imagepng($image, $path),
            'jpg' => imagejpeg($image, $path),
            'webp' => imagewebp($image, $path),
            'avif' => imageavif($image, $path),
            'gif' => imagegif($image, $path),
        };
    }
}

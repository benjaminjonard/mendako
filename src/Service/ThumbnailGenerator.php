<?php

declare(strict_types=1);

namespace App\Service;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;

class ThumbnailGenerator
{
    function generate(string $path, string $thumbnailPath, int $thumbnailWidth, ?string $thumbnailMimeType = null): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $mime = mime_content_type($path);
        if ($mime === 'video/mp4' || $mime === 'video/webm') {
            $ffmpeg = FFMpeg::create();
            $video = $ffmpeg->open($path);
            $stream = $video->getStreams()->videos()->first();
            $width = $stream->getDimensions()->getWidth();
            $height = $stream->getDimensions()->getHeight();
        } else {
            [$width, $height] = getimagesize($path);
        }

        if ($width <= $thumbnailWidth) {
            $thumbnailWidth = $width;
        }

        $thumbnailHeight = (int) floor($height * ($thumbnailWidth / $width));

        // Create user directory in uploads
        $dir = explode('/', $thumbnailPath);
        array_pop($dir);
        $dir = implode('/', $dir);

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \Exception('There was a problem while creating the thumbnail. Please try again!');
        }

        if ($mime === 'video/mp4' || $mime === 'video/webm') {
            $video->frame(TimeCode::fromSeconds(1))->save($thumbnailPath);;
        } else {
            $image = match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($path),
                'image/png' => imagecreatefrompng($path),
                'image/webp' => imagecreatefromwebp($path),
                'image/gif' => imagecreatefromgif($path),
                'image/avif' => imagecreatefromavif($path),
                default => throw new \Exception('Your image cannot be processed, please use another one.'),
            };

            $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);

            // Transparency
            if (in_array($mime, ['image/png', 'image/webp', 'image/avif'])) {
                imagecolortransparent($thumbnail, imagecolorallocate($thumbnail, 0, 0, 0));
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
            }

            imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $thumbnailWidth, $thumbnailHeight, $width, $height);
            $deg = $this->guessRotation($path);
            $thumbnail = imagerotate($thumbnail, $deg, 0);

            $mime = $thumbnailMimeType ?? $mime;
            match ($mime) {
                'image/jpeg' => imagejpeg($thumbnail, $thumbnailPath),
                'image/png' => imagepng($thumbnail, $thumbnailPath),
                'image/webp' => imagewebp($thumbnail, $thumbnailPath),
                'image/avif' => imageavif($thumbnail, $thumbnailPath),
                'image/gif' => imagegif($thumbnail, $thumbnailPath),
            };
        }

        return true;
    }

    function guessRotation(string $path): int
    {
        $deg = 0;

        if (\function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            if ($exif && isset($exif['Orientation'])) {
                $orientation = $exif['Orientation'];
                if (1 != $orientation) {
                    switch ($orientation) {
                        case 3:
                            $deg = 180;
                            break;
                        case 6:
                            $deg = 270;
                            break;
                        case 8:
                            $deg = 90;
                            break;
                    }
                }
            }
        }

        return $deg;
    }
}
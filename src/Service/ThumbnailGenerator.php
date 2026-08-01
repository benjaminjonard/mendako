<?php

declare(strict_types=1);

namespace App\Service;

use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use Symfony\Component\Process\Process;

class ThumbnailGenerator
{
    public const array VIDEO_MIMETYPES = ['video/mp4', 'video/webm', 'video/x-m4v', 'image/gif'];

    /**
     * Extract up to `$count` resized JPEG frames at evenly-spaced timecodes — used to
     * sample a video's content for automatic tagging. Returns the written frame paths (fewer
     * than `$count` if the clip is too short to yield distinct frames). Non-video → [].
     */
    public function extractVideoFrames(string $path, string $destDir, int $count, int $width): array
    {
        if (!is_file($path) || !in_array(mime_content_type($path), self::VIDEO_MIMETYPES, true)) {
            return [];
        }

        if (!is_dir($destDir) && !mkdir($destDir, 0700, true) && !is_dir($destDir)) {
            throw new \Exception('Could not create the frame directory.');
        }

        $video = FFMpeg::create()->open($path);
        $stream = $video->getStreams()->videos()->first();
        if ($stream === null) {
            return []; // no video stream (e.g. audio-only container, or a corrupt clip)
        }
        $originalWidth = $stream->getDimensions()->getWidth();
        $originalHeight = $stream->getDimensions()->getHeight();
        if ($originalWidth <= 0 || $originalHeight <= 0) {
            return [];
        }

        $targetWidth = min($width, $originalWidth);
        $targetHeight = max(1, (int) floor($originalHeight * ($targetWidth / $originalWidth)));
        $video->filters()->resize(new Dimension($targetWidth, $targetHeight))->synchronize();

        $duration = (float) $video->getFormat()->get('duration');
        // A static gif / missing duration metadata reports ~0; sample a single frame
        // rather than `$count` identical ones at t=0.
        if ($duration <= 0.0) {
            $count = 1;
        }

        $paths = [];
        for ($i = 0; $i < $count; ++$i) {
            $second = $count === 1 ? 0.0 : $duration * (($i + 1) / ($count + 1));
            $framePath = $destDir.'/frame-'.$i.'.jpeg';
            try {
                // ffmpeg can't always encode a frame at a given timecode (e.g. the last
                // sampled position of a very short clip, or a frame the MJPEG encoder
                // rejects). A single unreadable frame must not lose the whole sample:
                // skip it and keep the frames that did decode.
                $video->frame(TimeCode::fromSeconds($second))->save($framePath);
            } catch (\Throwable) {
                continue;
            }
            if (is_file($framePath) && filesize($framePath) > 0) {
                $paths[] = $framePath;
            }
        }

        return $paths;
    }

    public function generate(string $path, string $thumbnailPath, int $thumbnailWidth, ?string $thumbnailsFormat = null): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $mime = mime_content_type($path);

        if ($mime === 'image/svg+xml') {
            // GD can't read SVG, so rasterize with ffmpeg (via librsvg) to feed the tagging
            // pipeline a raster. Display templates render the original SVG, so this only runs
            // for the on-demand thumbnailer and the tagging pipeline.
            $this->ensureDirectory($thumbnailPath);
            $this->rasterizeWithFfmpeg($path, $thumbnailPath, $thumbnailWidth);

            return true;
        }

        if ($mime === 'video/mp4' || $mime === 'video/webm' || $mime === 'image/gif' || $mime === 'video/x-m4v') {
            $ffmpeg = FFMpeg::create();
            $video = $ffmpeg->open($path);
            $stream = $video->getStreams()->videos()->first();
            if ($stream === null) {
                return false; // no video stream to thumbnail
            }
            $width = $stream->getDimensions()->getWidth();
            $height = $stream->getDimensions()->getHeight();
        } else {
            [$width, $height] = getimagesize($path);
        }

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        if ($width <= $thumbnailWidth) {
            $thumbnailWidth = $width;
        }

        $thumbnailHeight = (int) floor($height * ($thumbnailWidth / $width));

        $this->ensureDirectory($thumbnailPath);

        if ($mime === 'video/mp4' || $mime === 'video/webm' || $mime === 'image/gif' || $mime === 'video/x-m4v') {
            $second = $video->getFormat()->get('duration') * 0.1;
            $video->frame(TimeCode::fromSeconds($second))->save($thumbnailPath);
        } else {
            $image = match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($path),
                'image/png' => imagecreatefrompng($path),
                'image/webp' => imagecreatefromwebp($path),
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

            match ($thumbnailsFormat) {
                'jpeg' => imagejpeg($thumbnail, $thumbnailPath),
                'png' => imagepng($thumbnail, $thumbnailPath),
                'webp' => imagewebp($thumbnail, $thumbnailPath),
                'avif' => imageavif($thumbnail, $thumbnailPath)
            };
        }

        return true;
    }

    private function ensureDirectory(string $thumbnailPath): void
    {
        $dir = \dirname($thumbnailPath);

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \Exception('There was a problem while creating the thumbnail. Please try again!');
        }
    }

    /**
     * Rasterize/downscale an image with ffmpeg (out-of-process, bounded memory, librsvg for
     * SVG). The output format follows the thumbnail path's extension; never upscales.
     */
    private function rasterizeWithFfmpeg(string $sourcePath, string $thumbnailPath, int $width): void
    {
        $process = new Process([
            'ffmpeg', '-y', '-v', 'error',
            '-i', $sourcePath,
            '-vf', sprintf("scale='min(iw,%d)':-2", $width),
            '-frames:v', '1', '-update', '1',
            $thumbnailPath,
        ]);
        $process->run();

        if (!$process->isSuccessful() || !is_file($thumbnailPath)) {
            throw new \Exception('ffmpeg could not generate the thumbnail: '.$process->getErrorOutput());
        }
    }

    public function guessRotation(string $path): int
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
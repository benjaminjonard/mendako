<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\File;

class PostVectorService
{
    public function __construct(private readonly ThumbnailGenerator $thumbnailGenerator)
    {
    }

    /**
     * Generate complete feature vector combining multiple image features
     */
    public function generateVector(?File $file): ?string
    {
        $image = $this->loadImage($file);
        if (!$image) {
            return null;
        }

        $features = [];

        // 1. Perceptual hash as binary features (64 dimensions)
        $hashBits = $this->calculatePerceptualHashBits($image);
        $features = array_merge($features, $hashBits);

        // 2. Color histogram (192 dimensions: 64 bins × 3 channels)
        $histogram = $this->calculateColorHistogram($image);
        $features = array_merge($features, $histogram);

        // 3. Dominant colors (15 dimensions: 5 colors × 3 channels)
        $dominantColors = $this->extractDominantColorsFlat($image);
        $features = array_merge($features, $dominantColors);

        // Normalize to [0, 1] range for better distance calculations
        $features = $this->normalizeVector($features);

        return '[' . implode(',', $features) . ']';
    }

    /**
     * Calculate perceptual hash and return as bit array
     */
    private function calculatePerceptualHashBits(\GdImage $image): array
    {
        // Resize to 9x8 for dHash
        $resized = imagecreatetruecolor(9, 8);
        imagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            9, 8,
            imagesx($image), imagesy($image)
        );

        $bits = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb1 = imagecolorat($resized, $x, $y);
                $rgb2 = imagecolorat($resized, $x + 1, $y);

                $gray1 = $this->rgbToGray($rgb1);
                $gray2 = $this->rgbToGray($rgb2);

                $bits[] = ($gray1 > $gray2) ? 1.0 : 0.0;
            }
        }

        return $bits;
    }

    /**
     * Calculate color histogram (64 bins per channel)
     */
    private function calculateColorHistogram(\GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Initialize histogram: 64 bins for each R, G, B
        $histR = array_fill(0, 64, 0);
        $histG = array_fill(0, 64, 0);
        $histB = array_fill(0, 64, 0);

        $step = max(1, min($width, $height) / 100);
        $totalPixels = 0;

        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                $rgb = imagecolorat($image, (int)$x, (int)$y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Map to 64 bins (0-255 → 0-63)
                $histR[(int)($r / 4)]++;
                $histG[(int)($g / 4)]++;
                $histB[(int)($b / 4)]++;
                $totalPixels++;
            }
        }

        // Normalize histogram
        $histogram = [];
        foreach ([$histR, $histG, $histB] as $hist) {
            foreach ($hist as $count) {
                $histogram[] = $totalPixels > 0 ? $count / $totalPixels : 0;
            }
        }

        return $histogram;
    }

    /**
     * Extract dominant colors as flat array
     */
    private function extractDominantColorsFlat(\GdImage $image, int $count = 5): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $colors = [];
        $step = max(1, min($width, $height) / 100);

        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                $rgb = imagecolorat($image, (int)$x, (int)$y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $key = sprintf('%d,%d,%d',
                    (int)($r / 8) * 8,
                    (int)($g / 8) * 8,
                    (int)($b / 8) * 8
                );

                if (!isset($colors[$key])) {
                    $colors[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                }
                $colors[$key]['count']++;
                $colors[$key]['r'] += $r;
                $colors[$key]['g'] += $g;
                $colors[$key]['b'] += $b;
            }
        }

        uasort($colors, fn($a, $b) => $b['count'] <=> $a['count']);

        $flat = [];
        $i = 0;
        foreach ($colors as $color) {
            if ($i++ >= $count) break;

            // Normalize to [0, 1]
            $flat[] = ($color['r'] / $color['count']) / 255;
            $flat[] = ($color['g'] / $color['count']) / 255;
            $flat[] = ($color['b'] / $color['count']) / 255;
        }

        // Pad with zeros if we don't have enough colors
        while (count($flat) < $count * 3) {
            $flat[] = 0.0;
        }

        return $flat;
    }

    /**
     * Extract dominant colors for storage (not part of vector)
     */
    public function extractDominantColors(File $file, int $count = 5): array
    {
        $image = $this->loadImage($file);
        if (!$image) {
            return [];
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $colors = [];
        $step = max(1, min($width, $height) / 100);

        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                $rgb = imagecolorat($image, (int)$x, (int)$y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $key = sprintf('%d,%d,%d',
                    (int)($r / 8) * 8,
                    (int)($g / 8) * 8,
                    (int)($b / 8) * 8
                );

                if (!isset($colors[$key])) {
                    $colors[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                }
                $colors[$key]['count']++;
                $colors[$key]['r'] += $r;
                $colors[$key]['g'] += $g;
                $colors[$key]['b'] += $b;
            }
        }

        uasort($colors, fn($a, $b) => $b['count'] <=> $a['count']);

        $dominantColors = [];
        $i = 0;
        foreach ($colors as $color) {
            if ($i++ >= $count) break;

            $dominantColors[] = [
                'r' => (int)($color['r'] / $color['count']),
                'g' => (int)($color['g'] / $color['count']),
                'b' => (int)($color['b'] / $color['count']),
            ];
        }

        return $dominantColors;
    }

    /**
     * Calculate perceptual hash as hex string
     */
    public function calculatePerceptualHash(File $file): ?string
    {
        $image = $this->loadImage($file);
        if (!$image) {
            return null;
        }

        $resized = imagecreatetruecolor(9, 8);
        imagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            9, 8,
            imagesx($image), imagesy($image)
        );

        $hash = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb1 = imagecolorat($resized, $x, $y);
                $rgb2 = imagecolorat($resized, $x + 1, $y);

                $gray1 = $this->rgbToGray($rgb1);
                $gray2 = $this->rgbToGray($rgb2);

                $hash .= ($gray1 > $gray2) ? '1' : '0';
            }
        }

        return str_pad(base_convert($hash, 2, 16), 16, '0', STR_PAD_LEFT);
    }

    private function normalizeVector(array $vector): array
    {
        // L2 normalization
        $magnitude = sqrt(array_sum(array_map(fn($v) => $v * $v, $vector)));

        if ($magnitude == 0) {
            return $vector;
        }

        return array_map(fn($v) => $v / $magnitude, $vector);
    }

    private function loadImage(?File $file): ?\GdImage
    {
        if (!$file) {
            return null;
        }

        $path = $file->getRealPath();
        $thumbnailPath = '/tmp/' . $file->getFilename() .  '_600.jpeg';
        $this->thumbnailGenerator->generate($path, $thumbnailPath, 600, 'jpeg');

        return @imagecreatefromjpeg($thumbnailPath);
    }

    private function rgbToGray(int $rgb): int
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
    }
}
<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\File;

class PostVectorService
{
    private const int DCT_SIZE = 32;
    private const int HASH_SIZE = 8;

    public function __construct(private readonly ThumbnailGenerator $thumbnailGenerator)
    {
    }

    public function generateVector(?File $file): ?string
    {
        $image = $this->loadImage($file);
        if (!$image instanceof \GdImage) {
            return null;
        }

        $bits = $this->perceptualHashBits($image);

        return '['.implode(',', $bits) . ']';
    }

    private function perceptualHashBits(\GdImage $image): array
    {
        $luma = $this->luminanceGrid($image);

        $upright = $this->hashFromLuma($luma, false);
        $mirrored = $this->hashFromLuma($luma, true);

        $bits = implode('', $upright) <= implode('', $mirrored) ? $upright : $mirrored;

        return array_map(static fn (int $bit): float => (float) $bit, $bits);
    }

    private function luminanceGrid(\GdImage $image): array
    {
        $resized = imagecreatetruecolor(self::DCT_SIZE, self::DCT_SIZE);
        imagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            self::DCT_SIZE, self::DCT_SIZE,
            imagesx($image), imagesy($image),
        );

        $grid = [];
        for ($y = 0; $y < self::DCT_SIZE; ++$y) {
            for ($x = 0; $x < self::DCT_SIZE; ++$x) {
                $grid[$y][$x] = (float) $this->rgbToGray(imagecolorat($resized, $x, $y));
            }
        }

        return $grid;
    }

    private function hashFromLuma(array $luma, bool $flip): array
    {
        if ($flip) {
            $luma = array_map(static fn (array $row): array => array_reverse($row), $luma);
        }

        $dct = $this->dct2d($luma);

        $block = [];
        for ($u = 0; $u < self::HASH_SIZE; ++$u) {
            for ($v = 0; $v < self::HASH_SIZE; ++$v) {
                $block[] = $dct[$u][$v];
            }
        }

        $withoutDc = \array_slice($block, 1);
        sort($withoutDc);
        $mid = intdiv(\count($withoutDc), 2);
        $median = (\count($withoutDc) % 2 === 0)
            ? ($withoutDc[$mid - 1] + $withoutDc[$mid]) / 2
            : $withoutDc[$mid];

        return array_map(static fn (float $coef): int => $coef > $median ? 1 : 0, $block);
    }

    private function dct2d(array $matrix): array
    {
        $rows = [];
        for ($y = 0; $y < self::DCT_SIZE; ++$y) {
            $rows[$y] = $this->dct1d($matrix[$y]);
        }

        $out = [];
        for ($x = 0; $x < self::DCT_SIZE; ++$x) {
            $column = [];
            for ($y = 0; $y < self::DCT_SIZE; ++$y) {
                $column[$y] = $rows[$y][$x];
            }
            $transformed = $this->dct1d($column);
            for ($y = 0; $y < self::DCT_SIZE; ++$y) {
                $out[$y][$x] = $transformed[$y];
            }
        }

        return $out;
    }

    private function dct1d(array $signal): array
    {
        $out = [];
        for ($k = 0; $k < self::DCT_SIZE; ++$k) {
            $sum = 0.0;
            for ($n = 0; $n < self::DCT_SIZE; ++$n) {
                $sum += $signal[$n] * cos(M_PI * ($n + 0.5) * $k / self::DCT_SIZE);
            }
            $out[$k] = $sum;
        }

        return $out;
    }

    private function loadImage(?File $file): ?\GdImage
    {
        if (!$file instanceof File) {
            return null;
        }

        $path = $file->getRealPath();
        $thumbnailPath = sys_get_temp_dir().'/mendako-phash-'.bin2hex(random_bytes(8)).'.jpeg';
        $image = false;
        try {
            $this->thumbnailGenerator->generate($path, $thumbnailPath, 600, 'jpeg');
            $image = @imagecreatefromjpeg($thumbnailPath);
        } finally {
            @unlink($thumbnailPath);
        }

        return $image instanceof \GdImage ? $image : null;
    }

    private function rgbToGray(int $rgb): int
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
    }
}

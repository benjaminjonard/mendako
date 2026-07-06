<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\File;

/**
 * Builds the perceptual "signature" used for near-duplicate / "similar posts" detection.
 *
 * The signature is a 64-bit DCT perceptual hash (pHash), stored as a 64-dimension 0/1 pgvector.
 * Because the components are binary, the pgvector L2 operator `<->` equals sqrt(Hamming distance),
 * so ordering/thresholding on `<->` is exactly a Hamming-distance nearest-neighbour search over the
 * hash.
 *
 * Mirror-invariance: the hash is canonicalised by taking the min of the image and its horizontal
 * flip, so a flipped repost (the most common imageboard variant) collapses to the same bits.
 *
 * This is a perceptual hash for exact/near-duplicate detection, NOT a semantic similarity vector:
 * it deliberately does not match distinct images that merely look alike.
 */
class PostVectorService
{
    /** DCT works on a 32x32 luminance grid; the low-frequency 8x8 block (64 coefficients) is hashed. */
    private const int DCT_SIZE = 32;
    private const int HASH_SIZE = 8;

    public function __construct(private readonly ThumbnailGenerator $thumbnailGenerator)
    {
    }

    /**
     * Generate the 64-dim binary pHash vector as a pgvector literal (e.g. "[0,1,1,0,...]"),
     * or null when the file can't be decoded.
     */
    public function generateVector(?File $file): ?string
    {
        $image = $this->loadImage($file);
        if (!$image instanceof \GdImage) {
            return null;
        }

        $bits = $this->perceptualHashBits($image);

        return '['.implode(',', $bits) . ']';
    }

    /**
     * 64-bit DCT pHash as bits (1.0/0.0), mirror-canonicalised.
     *
     * Hash the 32x32 luminance grid and its horizontal flip, then keep whichever bitstring is
     * smaller. Both an image and its mirror canonicalise to the same value, so a flipped repost
     * lands at Hamming distance 0.
     */
    private function perceptualHashBits(\GdImage $image): array
    {
        $luma = $this->luminanceGrid($image);

        $upright = $this->hashFromLuma($luma, false);
        $mirrored = $this->hashFromLuma($luma, true);

        // Canonical orientation = the lexicographically smaller bitstring.
        $bits = implode('', $upright) <= implode('', $mirrored) ? $upright : $mirrored;

        return array_map(static fn (int $bit): float => (float) $bit, $bits);
    }

    /**
     * Downscale to a DCT_SIZE x DCT_SIZE luminance grid once; both orientations reuse it.
     */
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

    /**
     * Compute the 64 hash bits for one orientation of the luminance grid.
     *
     * bit = low-frequency DCT coefficient > median-of-the-block. The DC term (0,0) is excluded from
     * the median so overall brightness doesn't skew the threshold — the standard pHash recipe.
     */
    private function hashFromLuma(array $luma, bool $flip): array
    {
        if ($flip) {
            $luma = array_map(static fn (array $row): array => array_reverse($row), $luma);
        }

        $dct = $this->dct2d($luma);

        // Top-left HASH_SIZE x HASH_SIZE block = the lowest spatial frequencies.
        $block = [];
        for ($u = 0; $u < self::HASH_SIZE; ++$u) {
            for ($v = 0; $v < self::HASH_SIZE; ++$v) {
                $block[] = $dct[$u][$v];
            }
        }

        // Median of the block excluding the DC coefficient (index 0).
        $withoutDc = \array_slice($block, 1);
        sort($withoutDc);
        $mid = intdiv(\count($withoutDc), 2);
        $median = (\count($withoutDc) % 2 === 0)
            ? ($withoutDc[$mid - 1] + $withoutDc[$mid]) / 2
            : $withoutDc[$mid];

        return array_map(static fn (float $coef): int => $coef > $median ? 1 : 0, $block);
    }

    /**
     * Separable 2D DCT-II: 1D DCT over rows, then over columns. Only the low-frequency corner is
     * consumed by the caller, but the full transform is cheap at 32x32 so we compute it whole.
     */
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

    /**
     * 1D DCT-II of a DCT_SIZE-length signal. Normalisation is irrelevant here: we only compare
     * coefficients to their own median, and any positive scaling preserves the ordering.
     */
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
        // Unique temp path avoids collisions between concurrent calls that share a basename.
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

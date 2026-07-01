<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Service\ThumbnailGenerator;
use PHPUnit\Framework\TestCase;

class ThumbnailGeneratorTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../assets/fixtures';

    public function test_extract_video_frames_returns_resized_jpegs(): void
    {
        $generator = new ThumbnailGenerator();
        $dir = sys_get_temp_dir().'/mendako-frames-'.bin2hex(random_bytes(8));

        $frames = $generator->extractVideoFrames(self::FIXTURES.'/nyancat.mp4', $dir, 3, 320);

        $this->assertCount(3, $frames);
        foreach ($frames as $frame) {
            $this->assertFileExists($frame);
            $this->assertGreaterThan(0, filesize($frame));
            $this->assertSame('image/jpeg', mime_content_type($frame));
            @unlink($frame);
        }
        @rmdir($dir);
    }

    public function test_extract_video_frames_returns_empty_for_non_video(): void
    {
        $generator = new ThumbnailGenerator();

        $this->assertSame([], $generator->extractVideoFrames(self::FIXTURES.'/nyancat.png', sys_get_temp_dir(), 3, 320));
    }
}

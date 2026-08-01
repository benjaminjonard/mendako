<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Service\RandomStringGenerator;
use App\Service\ThumbnailStorage;
use PHPUnit\Framework\TestCase;

class ThumbnailStorageTest extends TestCase
{
    private string $publicPath;

    #[\Override]
    protected function setUp(): void
    {
        $this->publicPath = sys_get_temp_dir().'/mendako-thumbnails-'.bin2hex(random_bytes(6));
        mkdir($this->publicPath, 0777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->publicPath)) {
            exec('rm -rf '.escapeshellarg($this->publicPath));
        }
    }

    private function storage(?string $format = 'webp'): ThumbnailStorage
    {
        return new ThumbnailStorage(new RandomStringGenerator(), $this->publicPath, $format);
    }

    public function test_mirrors_the_upload_directory_under_thumbnails(): void
    {
        $this->assertSame(
            'thumbnails/boards/abc/xyz.webp',
            $this->storage()->relativePathFor('uploads/boards/abc/xyz.png', 'image/png'),
        );
        $this->assertSame(
            'thumbnails/bulk-upload/xyz.webp',
            $this->storage()->relativePathFor('uploads/bulk-upload/xyz.png', 'image/png'),
        );
    }

    public function test_board_cover_is_named_randomly_so_a_new_one_is_not_served_from_cache(): void
    {
        $storage = $this->storage();

        $first = $storage->relativePathForBoard('board-1', 'image/png');
        $second = $storage->relativePathForBoard('board-1', 'image/png');

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('thumbnails/boards/board-1/', $first);
    }

    public function test_configured_format_wins_over_the_source(): void
    {
        $this->assertSame('webp', $this->storage('webp')->extensionFor('image/png'));
    }

    public function test_without_a_configured_format_the_source_format_is_kept(): void
    {
        $this->assertSame('png', $this->storage(null)->extensionFor('image/png'));
        $this->assertSame('jpeg', $this->storage('')->extensionFor('image/jpeg'));
    }

    public function test_sources_with_no_usable_image_extension_fall_back_to_jpeg(): void
    {
        // The old on-the-fly thumbnailer derived the extension by stripping "image/", which turned
        // a video into the literal extension "video/mp4".
        $this->assertSame('jpeg', $this->storage(null)->extensionFor('video/mp4'));
        $this->assertSame('jpeg', $this->storage(null)->extensionFor('image/svg+xml'));
        $this->assertSame('jpeg', $this->storage(null)->extensionFor(null));
    }

    public function test_purge_removes_only_unreferenced_files(): void
    {
        $storage = $this->storage();
        mkdir($this->publicPath.'/thumbnails/boards/abc', 0777, true);
        touch($kept = $this->publicPath.'/thumbnails/boards/abc/kept.webp');
        touch($orphan = $this->publicPath.'/thumbnails/boards/abc/orphan_360.webp');
        touch($dotfile = $this->publicPath.'/thumbnails/.gitkeep');

        $purged = $storage->purgeUnreferenced(['thumbnails/boards/abc/kept.webp']);

        $this->assertSame(1, $purged);
        $this->assertFileExists($kept);
        $this->assertFileDoesNotExist($orphan);
        $this->assertFileExists($dotfile);
    }

    public function test_purge_is_a_no_op_without_a_thumbnails_directory(): void
    {
        $this->assertSame(0, $this->storage()->purgeUnreferenced([]));
    }
}

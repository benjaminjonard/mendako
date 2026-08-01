<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Board;
use App\Entity\Post;
use App\Entity\StagedPost;
use App\Message\GenerateThumbnailMessage;
use App\MessageHandler\GenerateThumbnailHandler;
use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\StagedPostRepository;
use App\Service\RandomStringGenerator;
use App\Service\ThumbnailGenerator;
use App\Service\ThumbnailStorage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GenerateThumbnailHandlerTest extends TestCase
{
    private string $publicPath;

    #[\Override]
    protected function setUp(): void
    {
        $this->publicPath = sys_get_temp_dir().'/mendako-thumb-handler-'.bin2hex(random_bytes(6));
        mkdir($this->publicPath, 0777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->publicPath)) {
            exec('rm -rf '.escapeshellarg($this->publicPath));
        }
    }

    private function handler(
        ThumbnailGenerator $generator,
        ?PostRepository $postRepository = null,
        ?StagedPostRepository $stagedPostRepository = null,
        ?BoardRepository $boardRepository = null,
        ?EntityManagerInterface $entityManager = null,
    ): GenerateThumbnailHandler {
        return new GenerateThumbnailHandler(
            $postRepository ?? $this->createStub(PostRepository::class),
            $stagedPostRepository ?? $this->createStub(StagedPostRepository::class),
            $boardRepository ?? $this->createStub(BoardRepository::class),
            $generator,
            new ThumbnailStorage(new RandomStringGenerator(), $this->publicPath, 'webp'),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    private function generatorWriting(bool $success = true): ThumbnailGenerator
    {
        $generator = $this->createStub(ThumbnailGenerator::class);
        $generator->method('generate')->willReturnCallback(
            function (string $source, string $destination) use ($success): bool {
                if ($success) {
                    @mkdir(\dirname($destination), 0777, true);
                    touch($destination);
                }

                return $success;
            },
        );

        return $generator;
    }

    private function post(?string $thumbnailPath = null): Post
    {
        $post = new Post();
        $post->setPath('uploads/boards/b1/image.png')->setMimetype('image/png')->setThumbnailPath($thumbnailPath);

        return $post;
    }

    public function test_stores_the_generated_path_on_a_post(): void
    {
        $post = $this->post();
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $handler = $this->handler($this->generatorWriting(), $postRepository, entityManager: $entityManager);
        $handler(new GenerateThumbnailMessage(GenerateThumbnailMessage::TARGET_POST, 'id'));

        $this->assertSame('thumbnails/boards/b1/image.webp', $post->getThumbnailPath());
    }

    public function test_stores_the_generated_path_on_a_staged_post(): void
    {
        $stagedPost = new StagedPost();
        $stagedPost->setPath('uploads/bulk-upload/image.png')->setMimetype('image/png');
        $stagedPostRepository = $this->createStub(StagedPostRepository::class);
        $stagedPostRepository->method('find')->willReturn($stagedPost);

        $handler = $this->handler($this->generatorWriting(), stagedPostRepository: $stagedPostRepository);
        $handler(new GenerateThumbnailMessage(GenerateThumbnailMessage::TARGET_STAGED_POST, 'id'));

        $this->assertSame('thumbnails/bulk-upload/image.webp', $stagedPost->getThumbnailPath());
    }

    public function test_board_cover_is_derived_from_the_designated_post(): void
    {
        $board = new Board();
        $board->setThumbnail($this->post());
        $boardRepository = $this->createStub(BoardRepository::class);
        $boardRepository->method('find')->willReturn($board);

        $handler = $this->handler($this->generatorWriting(), boardRepository: $boardRepository);
        $handler(new GenerateThumbnailMessage(GenerateThumbnailMessage::TARGET_BOARD, 'id'));

        $this->assertStringStartsWith('thumbnails/boards/'.$board->getId().'/', (string) $board->getThumbnailPath());
    }

    public function test_board_without_a_designated_post_generates_nothing(): void
    {
        $board = new Board();
        $boardRepository = $this->createStub(BoardRepository::class);
        $boardRepository->method('find')->willReturn($board);
        $generator = $this->createMock(ThumbnailGenerator::class);
        $generator->expects($this->never())->method('generate');

        $handler = $this->handler($generator, boardRepository: $boardRepository);
        $handler(new GenerateThumbnailMessage(GenerateThumbnailMessage::TARGET_BOARD, 'id'));

        $this->assertNull($board->getThumbnailPath());
    }

    public function test_previous_thumbnail_is_deleted_once_the_new_path_is_stored(): void
    {
        mkdir($this->publicPath.'/thumbnails/boards/b1', 0777, true);
        touch($previous = $this->publicPath.'/thumbnails/boards/b1/old.webp');

        $post = $this->post('thumbnails/boards/b1/old.webp');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        $handler = $this->handler($this->generatorWriting(), $postRepository);
        $handler(new GenerateThumbnailMessage(GenerateThumbnailMessage::TARGET_POST, 'id'));

        $this->assertFileDoesNotExist($previous);
        $this->assertSame('thumbnails/boards/b1/image.webp', $post->getThumbnailPath());
    }

    public function test_a_failing_generator_leaves_the_stored_path_untouched(): void
    {
        $post = $this->post('thumbnails/boards/b1/old.webp');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);
        $generator = $this->createStub(ThumbnailGenerator::class);
        $generator->method('generate')->willThrowException(new \RuntimeException('boom'));

        $handler = $this->handler($generator, $postRepository);

        // Must not throw: a corrupt source would otherwise dead-letter and retry forever.
        $handler(new GenerateThumbnailMessage(GenerateThumbnailMessage::TARGET_POST, 'id'));

        $this->assertSame('thumbnails/boards/b1/old.webp', $post->getThumbnailPath());
    }

    public function test_a_missing_target_is_a_no_op(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn(null);
        $generator = $this->createMock(ThumbnailGenerator::class);
        $generator->expects($this->never())->method('generate');

        $handler = $this->handler($generator, $postRepository);
        $handler(new GenerateThumbnailMessage(GenerateThumbnailMessage::TARGET_POST, 'gone'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Board;
use App\Entity\Post;
use App\Entity\StagedPost;
use App\Message\EnqueueThumbnailBacklogMessage;
use App\Message\GenerateThumbnailMessage;
use App\MessageHandler\EnqueueThumbnailBacklogHandler;
use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\StagedPostRepository;
use App\Service\ThumbnailStorage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class EnqueueThumbnailBacklogHandlerTest extends TestCase
{
    /** @var array<int, array{string, string}> */
    private array $dispatched = [];

    private function bus(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message): Envelope {
                $this->dispatched[] = [$message->targetType, $message->id];

                return new Envelope($message);
            },
        );

        return $bus;
    }

    private function boardWithCover(): Board
    {
        $post = new Post();
        $post->setPath('uploads/boards/b1/cover.png')->setMimetype('image/png');

        return (new Board())->setThumbnail($post);
    }

    private function handler(
        array $posts = [],
        array $stagedPosts = [],
        array $boards = [],
        ?ThumbnailStorage $storage = null,
    ): EnqueueThumbnailBacklogHandler {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('findWithoutThumbnailIterable')->willReturn($posts);
        $postRepository->method('findAllIterable')->willReturn($posts);
        $postRepository->method('thumbnailPaths')->willReturn(['thumbnails/boards/b1/kept.webp']);

        $stagedPostRepository = $this->createStub(StagedPostRepository::class);
        $stagedPostRepository->method('findWithoutThumbnailIterable')->willReturn($stagedPosts);
        $stagedPostRepository->method('findAllIterable')->willReturn($stagedPosts);
        $stagedPostRepository->method('thumbnailPaths')->willReturn(['thumbnails/bulk-upload/staged.webp']);

        $boardRepository = $this->createStub(BoardRepository::class);
        $boardRepository->method('findWithoutThumbnailIterable')->willReturn($boards);
        $boardRepository->method('findWithCoverIterable')->willReturn($boards);
        $boardRepository->method('thumbnailPaths')->willReturn(['thumbnails/boards/b1/cover.webp']);

        return new EnqueueThumbnailBacklogHandler(
            $postRepository,
            $stagedPostRepository,
            $boardRepository,
            $storage ?? $this->createStub(ThumbnailStorage::class),
            $this->bus(),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    public function test_boards_are_enqueued_alongside_posts_and_staged_posts(): void
    {
        $post = new Post();
        $staged = new StagedPost();
        $board = $this->boardWithCover();

        $handler = $this->handler([$post], [$staged], [$board]);
        $handler(new EnqueueThumbnailBacklogMessage());

        $this->assertSame([
            [GenerateThumbnailMessage::TARGET_POST, (string) $post->getId()],
            [GenerateThumbnailMessage::TARGET_STAGED_POST, (string) $staged->getId()],
            [GenerateThumbnailMessage::TARGET_BOARD, $board->getId()],
        ], $this->dispatched);
    }

    public function test_all_mode_also_covers_boards(): void
    {
        $board = $this->boardWithCover();

        $handler = $this->handler(boards: [$board]);
        $handler(new EnqueueThumbnailBacklogMessage(all: true));

        $this->assertSame([[GenerateThumbnailMessage::TARGET_BOARD, $board->getId()]], $this->dispatched);
    }

    public function test_nothing_is_enqueued_when_every_target_is_up_to_date(): void
    {
        $handler = $this->handler();
        $handler(new EnqueueThumbnailBacklogMessage());

        $this->assertSame([], $this->dispatched);
    }

    public function test_purge_runs_first_and_spares_every_stored_path(): void
    {
        $order = [];
        $storage = $this->createMock(ThumbnailStorage::class);
        $storage->expects($this->once())->method('purgeUnreferenced')->willReturnCallback(
            function (array $referenced) use (&$order): int {
                // A board cover whose source post was deleted is still referenced, so it must be
                // spared even though no message will ever regenerate it.
                $order[] = ['purge', $referenced];

                return 0;
            },
        );

        $handler = $this->handler([new Post()], storage: $storage);
        $handler(new EnqueueThumbnailBacklogMessage());

        $this->assertCount(1, $order);
        $this->assertSame([
            'thumbnails/boards/b1/kept.webp',
            'thumbnails/bulk-upload/staged.webp',
            'thumbnails/boards/b1/cover.webp',
        ], $order[0][1]);
        $this->assertNotSame([], $this->dispatched, 'the fan-out still runs after the purge');
    }
}

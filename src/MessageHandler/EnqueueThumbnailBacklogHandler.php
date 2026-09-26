<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnqueueThumbnailBacklogMessage;
use App\Message\GenerateThumbnailMessage;
use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\StagedPostRepository;
use App\Service\ThumbnailStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsMessageHandler]
final class EnqueueThumbnailBacklogHandler
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly StagedPostRepository $stagedPostRepository,
        private readonly BoardRepository $boardRepository,
        private readonly ThumbnailStorage $thumbnailStorage,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EnqueueThumbnailBacklogMessage $message): void
    {
        $purged = $this->thumbnailStorage->purgeUnreferenced([
            ...$this->postRepository->thumbnailPaths(),
            ...$this->stagedPostRepository->thumbnailPaths(),
            ...$this->boardRepository->thumbnailPaths(),
        ]);
        $this->logger->info('Purged unreferenced thumbnails', ['count' => $purged]);

        $sources = $message->all
            ? [
                GenerateThumbnailMessage::TARGET_POST => $this->postRepository->findAllIterable(),
                GenerateThumbnailMessage::TARGET_STAGED_POST => $this->stagedPostRepository->findAllIterable(),
                GenerateThumbnailMessage::TARGET_BOARD => $this->boardRepository->findWithCoverIterable(),
            ]
            : [
                GenerateThumbnailMessage::TARGET_POST => $this->postRepository->findWithoutThumbnailIterable(),
                GenerateThumbnailMessage::TARGET_STAGED_POST => $this->stagedPostRepository->findWithoutThumbnailIterable(),
                GenerateThumbnailMessage::TARGET_BOARD => $this->boardRepository->findWithoutThumbnailIterable(),
            ];

        $count = 0;
        foreach ($sources as $targetType => $items) {
            foreach ($items as $item) {
                $this->messageBus->dispatch(
                    new GenerateThumbnailMessage($targetType, (string) $item->getId()),
                    [new TransportNamesStamp('autotag_batch')],
                );
                if (++$count % 100 === 0) {
                    $this->entityManager->clear();
                }
            }
        }
    }
}

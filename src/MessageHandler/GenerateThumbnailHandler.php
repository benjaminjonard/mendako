<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateThumbnailMessage;
use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\StagedPostRepository;
use App\Service\ThumbnailGenerator;
use App\Service\ThumbnailStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Idempotent and soft-failing: on any error the stored path is left as it was, which the templates
 * render as the default image.
 */
#[AsMessageHandler]
final class GenerateThumbnailHandler
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly StagedPostRepository $stagedPostRepository,
        private readonly BoardRepository $boardRepository,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly ThumbnailStorage $thumbnailStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateThumbnailMessage $message): void
    {
        $target = $this->resolve($message);
        if ($target === null) {
            return;
        }

        [$entity, $sourcePath, $mimetype, $width, $relativePath] = $target;

        $previous = $entity->getThumbnailPath();

        try {
            $generated = $this->thumbnailGenerator->generate(
                $this->thumbnailStorage->absolutePath($sourcePath),
                $this->thumbnailStorage->absolutePath($relativePath),
                $width,
                $this->thumbnailStorage->extensionFor($mimetype),
            );

            if (!$generated) {
                $this->logger->warning('Thumbnail generation produced nothing', ['target' => $message->targetType, 'id' => $message->id]);

                return;
            }

            $entity->setThumbnailPath($relativePath);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->thumbnailStorage->remove($relativePath);
            $this->logger->warning('Thumbnail generation failed', [
                'target' => $message->targetType,
                'id' => $message->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        // After the commit, so a failure never leaves the entity pointing at a deleted file.
        if ($previous !== null && $previous !== $relativePath) {
            $this->thumbnailStorage->remove($previous);
        }
    }

    /**
     * @return array{0: \App\Entity\ThumbnailableInterface, 1: string, 2: ?string, 3: int, 4: string}|null
     */
    private function resolve(GenerateThumbnailMessage $message): ?array
    {
        if ($message->targetType === GenerateThumbnailMessage::TARGET_BOARD) {
            $board = $this->boardRepository->find($message->id);
            $source = $board?->getThumbnail();
            if ($board === null || $source === null || $source->getPath() === null) {
                return null;
            }

            return [
                $board,
                $source->getPath(),
                $source->getMimetype(),
                ThumbnailStorage::BOARD_WIDTH,
                $this->thumbnailStorage->relativePathForBoard($board->getId(), $source->getMimetype()),
            ];
        }

        $entity = $message->targetType === GenerateThumbnailMessage::TARGET_STAGED_POST
            ? $this->stagedPostRepository->find($message->id)
            : $this->postRepository->find($message->id);

        if ($entity === null || $entity->getPath() === null) {
            return null;
        }

        return [
            $entity,
            $entity->getPath(),
            $entity->getMimetype(),
            ThumbnailStorage::POST_WIDTH,
            $this->thumbnailStorage->relativePathFor($entity->getPath(), $entity->getMimetype()),
        ];
    }
}

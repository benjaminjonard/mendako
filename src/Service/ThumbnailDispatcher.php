<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Board;
use App\Entity\Post;
use App\Entity\StagedPost;
use App\Entity\ThumbnailableInterface;
use App\Message\GenerateThumbnailMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Messages must never leave before the entity is committed, or the worker finds no row:
 * schedule() collects them during a flush, dispatchScheduled() releases them after it.
 */
class ThumbnailDispatcher
{
    /** @var array<string, GenerateThumbnailMessage> */
    private array $scheduled = [];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function dispatch(ThumbnailableInterface $entity): void
    {
        $message = $this->buildMessage($entity);
        if ($message !== null) {
            $this->messageBus->dispatch($message);
        }
    }

    public function schedule(ThumbnailableInterface $entity): void
    {
        $message = $this->buildMessage($entity);
        if ($message !== null) {
            $this->scheduled[$message->targetType.':'.$message->id] = $message;
        }
    }

    public function dispatchScheduled(): void
    {
        $scheduled = $this->scheduled;
        $this->scheduled = [];

        foreach ($scheduled as $message) {
            $this->messageBus->dispatch($message);
        }
    }

    private function buildMessage(ThumbnailableInterface $entity): ?GenerateThumbnailMessage
    {
        $targetType = match (true) {
            $entity instanceof Post => GenerateThumbnailMessage::TARGET_POST,
            $entity instanceof StagedPost => GenerateThumbnailMessage::TARGET_STAGED_POST,
            $entity instanceof Board => GenerateThumbnailMessage::TARGET_BOARD,
            default => null,
        };

        return $targetType === null ? null : new GenerateThumbnailMessage($targetType, (string) $entity->getId());
    }
}

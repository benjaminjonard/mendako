<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\Post;
use App\Entity\StagedPost;
use App\Message\GenerateSuggestionsMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Feature-gated entry point for queuing auto-tag suggestion generation; a no-op when the feature is
 * disabled. Interactive uploads use the default routing; retroactive/backlog runs use dispatchBatch()
 * which routes to the deprioritized autotag_batch transport.
 */
class TaggingDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
    ) {
    }

    public function dispatch(Post|StagedPost $item): void
    {
        $message = $this->buildMessage($item);
        if ($message === null) {
            return;
        }

        $this->messageBus->dispatch($message);
    }

    /**
     * Queue retroactive/backlog tagging on the deprioritized autotag_batch transport so it
     * never competes with interactive uploads.
     */
    public function dispatchBatch(Post|StagedPost $item): void
    {
        $message = $this->buildMessage($item);
        if ($message === null) {
            return;
        }

        $this->messageBus->dispatch($message, [new TransportNamesStamp('autotag_batch')]);
    }

    private function buildMessage(Post|StagedPost $item): ?GenerateSuggestionsMessage
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return null;
        }

        $targetType = $item instanceof StagedPost ? 'bulk' : 'post';

        if ($item instanceof Post && !$this->autoTagConfigProvider->isBoardEnabled($item->getBoard()?->getSlug())) {
            return null;
        }

        return new GenerateSuggestionsMessage($targetType, (string) $item->getId());
    }
}

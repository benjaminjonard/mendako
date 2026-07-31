<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\Post;
use App\Message\GenerateSuggestionsMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Feature-gated entry point for queuing auto-tag suggestion generation; a no-op when the feature is
 * disabled or when the post's board is configured for no model. Interactive uploads use the default
 * routing; retroactive/backlog runs use dispatchBatch() which routes to the deprioritized
 * autotag_batch transport.
 *
 * Only posts are tagged: a staged post carries no board, so no model can be resolved for it, and the
 * post created when a staging is assigned to a board is dispatched on its own.
 */
class TaggingDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
    ) {
    }

    public function dispatch(Post $post): void
    {
        $message = $this->buildMessage($post);
        if ($message === null) {
            return;
        }

        $this->messageBus->dispatch($message);
    }

    /**
     * Queue retroactive/backlog tagging on the deprioritized autotag_batch transport so it
     * never competes with interactive uploads.
     */
    public function dispatchBatch(Post $post): void
    {
        $message = $this->buildMessage($post);
        if ($message === null) {
            return;
        }

        $this->messageBus->dispatch($message, [new TransportNamesStamp('autotag_batch')]);
    }

    private function buildMessage(Post $post): ?GenerateSuggestionsMessage
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return null;
        }

        if (!$this->autoTagConfigProvider->isBoardEnabled($post->getBoard()?->getSlug())) {
            return null;
        }

        return new GenerateSuggestionsMessage((string) $post->getId());
    }
}

<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\Post;
use App\Entity\StagedUpload;
use App\Message\GenerateSuggestionsMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Feature-gated entry point for queuing Automatic tags generation.
 * No-op when the automatic tagging feature is disabled (no dispatch, no runtime cost).
 *
 * Interactive uploads use the default routing (autotag_interactive); retroactive/backlog
 * runs use dispatchBatch() which routes to the deprioritized autotag_batch transport.
 */
class TaggingDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
    ) {
    }

    public function dispatch(Post|StagedUpload $item): void
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
    public function dispatchBatch(Post|StagedUpload $item): void
    {
        $message = $this->buildMessage($item);
        if ($message === null) {
            return;
        }

        $this->messageBus->dispatch($message, [new TransportNamesStamp('autotag_batch')]);
    }

    private function buildMessage(Post|StagedUpload $item): ?GenerateSuggestionsMessage
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return null;
        }

        $targetType = $item instanceof StagedUpload ? 'staged' : 'post';
        $contentHash = sha1((string) $item->getPath());

        return new GenerateSuggestionsMessage($targetType, (string) $item->getId(), $contentHash);
    }
}

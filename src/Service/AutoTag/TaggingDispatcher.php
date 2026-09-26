<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\Post;
use App\Message\GenerateSuggestionsMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

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

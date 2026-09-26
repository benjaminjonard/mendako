<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnqueueBacklogMessage;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\BacklogEnqueuer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EnqueueBacklogHandler
{
    public function __construct(
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        private readonly BacklogEnqueuer $backlogEnqueuer,
    ) {
    }

    public function __invoke(EnqueueBacklogMessage $message): void
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return;
        }

        $this->backlogEnqueuer->enqueue($message->all);
    }
}

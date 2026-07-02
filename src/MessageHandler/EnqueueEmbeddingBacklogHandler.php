<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnqueueEmbeddingBacklogMessage;
use App\Message\GenerateEmbeddingMessage;
use App\Repository\PostRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Fans out a bulk embedding run on the worker: queues one GenerateEmbeddingMessage per post
 * (missing an embedding, or all) on the deprioritized autotag_batch transport. Feature-gated.
 *
 * Posts only for now (bulk uploads come later). The trigger returns immediately even for a
 * large library because the per-item fan-out happens here, on the worker.
 */
#[AsMessageHandler]
final class EnqueueEmbeddingBacklogHandler
{
    public function __construct(
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        private readonly PostRepository $postRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(EnqueueEmbeddingBacklogMessage $message): void
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return;
        }

        $items = $message->all
            ? $this->postRepository->findAllIterable()
            : $this->postRepository->findWithoutEmbeddingIterable();

        $count = 0;
        foreach ($items as $item) {
            $this->messageBus->dispatch(
                new GenerateEmbeddingMessage('post', (string) $item->getId()),
                [new TransportNamesStamp('autotag_batch')],
            );
            if (++$count % 100 === 0) {
                $this->entityManager->clear(); // bound memory over a large back-catalogue
            }
        }
    }
}

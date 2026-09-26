<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnqueueVectorBacklogMessage;
use App\Message\GenerateVectorMessage;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsMessageHandler]
final class EnqueueVectorBacklogHandler
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(EnqueueVectorBacklogMessage $message): void
    {
        $items = $message->all
            ? $this->postRepository->findAllIterable()
            : $this->postRepository->findWithoutVectorIterable();

        $count = 0;
        foreach ($items as $item) {
            $this->messageBus->dispatch(
                new GenerateVectorMessage('post', (string) $item->getId()),
                [new TransportNamesStamp('autotag_batch')],
            );
            if (++$count % 100 === 0) {
                $this->entityManager->clear();
            }
        }
    }
}

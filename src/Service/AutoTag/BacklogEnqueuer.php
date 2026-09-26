<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;

class BacklogEnqueuer
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly TaggingDispatcher $taggingDispatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function enqueue(bool $all): int
    {
        $items = $all ? $this->postRepository->findAllIterable() : $this->postRepository->findWithoutSuggestionsIterable();

        $count = 0;
        foreach ($items as $item) {
            $this->taggingDispatcher->dispatchBatch($item);
            ++$count;
            if ($count % 100 === 0) {
                $this->entityManager->clear();
            }
        }

        return $count;
    }
}

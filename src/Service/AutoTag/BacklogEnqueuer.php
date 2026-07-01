<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Repository\PostRepository;
use App\Repository\StagedUploadRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Streams a backlog of posts (or staged uploads) and queues each for retroactive automatic tagging
 * tagging on the deprioritized autotag_batch transport. Shared by the console command and
 * the admin UI trigger so the selection/dispatch logic lives in one place.
 */
class BacklogEnqueuer
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly StagedUploadRepository $stagedUploadRepository,
        private readonly TaggingDispatcher $taggingDispatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int the number of items enqueued
     */
    public function enqueue(string $targetType, bool $all): int
    {
        $repository = $targetType === 'staged' ? $this->stagedUploadRepository : $this->postRepository;
        $items = $all ? $repository->findAllIterable() : $repository->findWithoutSuggestionsIterable();

        $count = 0;
        foreach ($items as $item) {
            $this->taggingDispatcher->dispatchBatch($item);
            ++$count;
            if ($count % 100 === 0) {
                $this->entityManager->clear(); // bound memory over a large back-catalogue
            }
        }

        return $count;
    }
}

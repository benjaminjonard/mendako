<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnqueueBacklogMessage;
use App\Repository\TagRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\AutoTag\BacklogEnqueuer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fans out a retroactive tagging run on the worker: queues one
 * GenerateSuggestionsMessage per backlog item. Feature-gated.
 *
 * Before fanning out, it encodes the zero-shot vocabulary and waits for that one-off to
 * finish, so images are only analyzed once the tag embeddings are cached — otherwise the
 * vocabulary warm-up and the per-image inference fight for the CPU and the early images get
 * an incomplete (or failed) zero-shot.
 */
#[AsMessageHandler]
final class EnqueueBacklogHandler
{
    public function __construct(
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        private readonly BacklogEnqueuer $backlogEnqueuer,
        private readonly TagRepository $tagRepository,
        private readonly AutoTagInferenceClient $autoTagInferenceClient,
        #[Autowire(service: 'monolog.logger.autotag')] private readonly LoggerInterface $logger,
        // Poll cadence/cap while waiting for the warm-up (only used on the async worker). The
        // encode of a large vocabulary can take minutes; the cap gives a generous ceiling.
        // Tests pass 0 to avoid real sleeps.
        private readonly int $vocabularyPollSeconds = 3,
        private readonly int $vocabularyMaxAttempts = 1200,
    ) {
    }

    public function __invoke(EnqueueBacklogMessage $message): void
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return;
        }

        $this->warmVocabularyAndWait();

        $this->backlogEnqueuer->enqueue($message->targetType, $message->all);
    }

    /**
     * Trigger the one-off vocabulary encode and block until it is cached (or the service is
     * unreachable / the cap is hit, in which case we proceed best-effort rather than stall).
     */
    private function warmVocabularyAndWait(): void
    {
        $clipModelId = $this->autoTagConfigProvider->getActiveModel('clip');
        if ($clipModelId === null) {
            return; // no zero-shot → nothing to warm
        }

        $tags = $this->tagRepository->findAllNames();
        if ($tags === []) {
            return;
        }

        $this->autoTagInferenceClient->warmVocabulary($clipModelId, $tags);
        $expected = count($tags);

        for ($attempt = 0; $attempt < $this->vocabularyMaxAttempts; ++$attempt) {
            $status = $this->autoTagInferenceClient->vocabularyStatus();
            if ($status === []) {
                return; // service unreachable — don't block the whole run
            }
            // Ready once every tag is encoded and the background task has stopped. `done` only
            // reaches `expected` on completion, so this never returns early on the start-up race.
            if (!($status['running'] ?? false) && (int) ($status['done'] ?? 0) >= $expected) {
                return;
            }
            if ($this->vocabularyPollSeconds > 0) {
                sleep($this->vocabularyPollSeconds);
            }
        }

        $this->logger->warning('Vocabulary warm-up did not finish before the cap; proceeding with tagging');
    }
}

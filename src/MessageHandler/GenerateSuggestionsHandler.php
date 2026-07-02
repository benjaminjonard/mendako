<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateSuggestionsMessage;
use App\Repository\EmbeddingRepository;
use App\Repository\PostRepository;
use App\Repository\BulkUploadRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\AutoTag\FrameResultAggregator;
use App\Service\AutoTag\KnnSuggestionService;
use App\Service\AutoTag\SuggestionService;
use App\Service\ThumbnailGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async tagging handler (Story 2.2/2.3/3.1): produces base-model tag suggestions
 * via the service (persisted as non-authoritative TagSuggestions, never tags) and,
 * when a CLIP model is active, stores a semantic embedding on the item.
 * Idempotent and feature-gated.
 */
#[AsMessageHandler]
final class GenerateSuggestionsHandler
{
    // Number of frames sampled from a video for content tagging.
    private const int VIDEO_FRAME_COUNT = 5;

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly BulkUploadRepository $bulkUploadRepository,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        private readonly AutoTagInferenceClient $autoTagInferenceClient,
        private readonly SuggestionService $suggestionService,
        private readonly KnnSuggestionService $knnSuggestionService,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly FrameResultAggregator $frameResultAggregator,
        private readonly EmbeddingRepository $embeddingRepository,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath,
        #[Autowire(service: 'monolog.logger.autotag')] private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateSuggestionsMessage $message): void
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return;
        }

        $item = $message->targetType === 'bulk'
            ? $this->bulkUploadRepository->find($message->id)
            : $this->postRepository->find($message->id);

        if ($item === null || $item->getPath() === null) {
            return; // removed before processing — idempotent no-op
        }

        $modelId = $this->autoTagConfigProvider->getActiveModel('wd');
        if ($modelId === null) {
            $this->logger->info('No active WD model; skipping tagging', ['target' => $message->targetType, 'id' => $message->id]);

            return;
        }

        $sourcePath = $this->publicPath.'/'.$item->getPath();
        $isVideo = in_array($item->getMimetype(), ThumbnailGenerator::VIDEO_MIMETYPES, true);
        $tempDir = null;
        $thumbnail = null;
        $vectors = []; // one embedding per frame (image → one)
        try {
            $result = null;
            if ($isVideo) {
                // Sample N frames: their tags are aggregated into one set, and every frame's
                // embedding is kept (so kNN can match on any frame).
                $tempDir = sys_get_temp_dir().'/mendako-autotag-'.bin2hex(random_bytes(8));
                $frames = $this->thumbnailGenerator->extractVideoFrames($sourcePath, $tempDir, self::VIDEO_FRAME_COUNT, 600);
                if ($frames !== []) {
                    $frameResults = [];
                    foreach ($frames as $framePath) {
                        $frameResult = $this->autoTagInferenceClient->analyze($framePath, $modelId);
                        $frameResults[] = $frameResult;
                        $this->collectVector($vectors, $frameResult);
                    }
                    $result = $this->frameResultAggregator->aggregate($frameResults);
                } else {
                    // A video the sampler couldn't read still gets the single-frame treatment.
                    $this->logger->warning('No video frames extracted; falling back to a single frame', ['target' => $message->targetType, 'id' => $message->id]);
                }
            }

            if ($result === null) {
                // Image, or the video fallback: one representative thumbnail (generate() reads videos too).
                $thumbnail = sys_get_temp_dir().'/mendako-autotag-'.bin2hex(random_bytes(8)).'.jpeg';
                $this->thumbnailGenerator->generate($sourcePath, $thumbnail, 600, 'jpeg');
                $result = $this->autoTagInferenceClient->analyze($thumbnail, $modelId);
                $this->collectVector($vectors, $result);
            }
        } catch (\Throwable $exception) {
            // Soft-fail: a bad/missing source image must not poison the worker.
            $this->logger->warning('automatic tagging failed', ['target' => $message->targetType, 'id' => $message->id, 'error' => $exception->getMessage()]);

            return;
        } finally {
            if ($thumbnail !== null) {
                @unlink($thumbnail);
            }
            // Recursive cleanup: a mid-extraction throw can leave partial frames the
            // handler never saw, so clear the dir's contents before removing it.
            if ($tempDir !== null && is_dir($tempDir)) {
                foreach (glob($tempDir.'/*') ?: [] as $frameFile) {
                    @unlink($frameFile);
                }
                @rmdir($tempDir);
            }
        }

        // Tag suggestions first — the primary feature. An embedding failure (below)
        // must never prevent these from being stored.
        if (!empty($result['tags']) || ($result['rating']['label'] ?? null) !== null) {
            $this->suggestionService->store($message->targetType, $message->id, $result);

            $this->logger->info('automatic tagging analysis stored suggestions', [
                'target' => $message->targetType,
                'id' => $message->id,
                'tag_count' => count($result['tags'] ?? []),
                'rating' => $result['rating']['label'] ?? null,
            ]);
        }

        // Semantic embeddings (one per frame) are additive + best-effort: isolate them so a
        // dimension mismatch or DB error logs to the autotag channel and never poisons the worker
        // (nor blocks the tag suggestions stored above).
        if ($vectors === []) {
            return;
        }

        // WD is the embedding encoder now (fc_norm feature), returned alongside the tags.
        $embeddingModelId = (string) ($result['embedding_model_id'] ?? $modelId);
        try {
            $this->embeddingRepository->replaceForTarget($message->targetType, $message->id, $embeddingModelId, $vectors);
        } catch (\Throwable $exception) {
            $this->logger->warning('automatic tagging embedding storage failed', ['target' => $message->targetType, 'id' => $message->id, 'error' => $exception->getMessage()]);

            return; // embeddings not stored — don't run kNN on unstored vectors
        }

        // Learned suggestions from the nearest already-tagged items. Best-effort:
        // a failure here must not block the WD suggestions already stored above.
        try {
            $this->knnSuggestionService->propagate($message->targetType, $message->id, $vectors, $embeddingModelId);
        } catch (\Throwable $exception) {
            $this->logger->warning('automatic tagging kNN propagation failed', ['target' => $message->targetType, 'id' => $message->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * @param string[] $vectors mutated in place: appends the result's embedding as a pgvector literal
     */
    private function collectVector(array &$vectors, array $result): void
    {
        $embedding = $result['embedding'] ?? null;
        if (is_array($embedding) && $embedding !== []) {
            $vectors[] = '['.implode(',', array_map('floatval', $embedding)).']';
        }
    }
}

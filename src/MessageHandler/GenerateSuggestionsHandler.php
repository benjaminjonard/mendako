<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\TagSuggestion;
use App\Message\GenerateSuggestionsMessage;
use App\Repository\PostRepository;
use App\Repository\StagedUploadRepository;
use App\Repository\TagRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\AutoTag\FrameResultAggregator;
use App\Service\AutoTag\KnnSuggestionService;
use App\Service\AutoTag\SuggestionService;
use App\Service\ThumbnailGenerator;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly StagedUploadRepository $stagedUploadRepository,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        private readonly AutoTagInferenceClient $autoTagInferenceClient,
        private readonly SuggestionService $suggestionService,
        private readonly KnnSuggestionService $knnSuggestionService,
        private readonly TagRepository $tagRepository,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly FrameResultAggregator $frameResultAggregator,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath,
        #[Autowire(service: 'monolog.logger.autotag')] private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateSuggestionsMessage $message): void
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return;
        }

        $item = $message->targetType === 'staged'
            ? $this->stagedUploadRepository->find($message->id)
            : $this->postRepository->find($message->id);

        if ($item === null || $item->getPath() === null) {
            return; // removed before processing — idempotent no-op
        }

        $modelId = $this->autoTagConfigProvider->getActiveModel('wd');
        if ($modelId === null) {
            $this->logger->info('No active WD model; skipping tagging', ['target' => $message->targetType, 'id' => $message->id]);

            return;
        }

        // CLIP embedding + zero-shot are folded into the same /analyze call (one thumbnail,
        // one request). Zero-shot scores the image against the user's full tag vocabulary;
        // the service encodes each name once and persists it, so passing them all is cheap.
        $clipModelId = $this->autoTagConfigProvider->getActiveModel('clip');
        $tagNames = $clipModelId !== null ? $this->tagRepository->findAllNames() : [];

        $sourcePath = $this->publicPath.'/'.$item->getPath();
        $isVideo = in_array($item->getMimetype(), ThumbnailGenerator::VIDEO_MIMETYPES, true);
        $tempDir = null;
        $thumbnail = null;
        try {
            $result = null;
            if ($isVideo) {
                // Sample N frames and aggregate their per-frame results into one set.
                $tempDir = sys_get_temp_dir().'/mendako-autotag-'.bin2hex(random_bytes(8));
                $frames = $this->thumbnailGenerator->extractVideoFrames($sourcePath, $tempDir, self::VIDEO_FRAME_COUNT, 600);
                if ($frames !== []) {
                    // The middle frame is representative: it alone carries the CLIP embedding + zero-shot.
                    $representative = intdiv(count($frames) - 1, 2);
                    $frameResults = [];
                    foreach ($frames as $index => $framePath) {
                        $isRepresentative = $index === $representative;
                        $frameResults[] = $this->autoTagInferenceClient->analyze(
                            $framePath,
                            $modelId,
                            $isRepresentative ? $clipModelId : null,
                            $isRepresentative ? $tagNames : [],
                        );
                    }
                    $result = $this->frameResultAggregator->aggregate($frameResults, $representative);
                } else {
                    // A video the sampler couldn't read still gets the single-frame treatment.
                    $this->logger->warning('No video frames extracted; falling back to a single frame', ['target' => $message->targetType, 'id' => $message->id]);
                }
            }

            if ($result === null) {
                // Image, or the video fallback: one representative thumbnail (generate() reads videos too).
                $thumbnail = sys_get_temp_dir().'/mendako-autotag-'.bin2hex(random_bytes(8)).'.jpeg';
                $this->thumbnailGenerator->generate($sourcePath, $thumbnail, 600, 'jpeg');
                $result = $this->autoTagInferenceClient->analyze($thumbnail, $modelId, $clipModelId, $tagNames);
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

        // Zero-shot suggestions (image scored against the user's own tag names) — a
        // `clip`-source guess, additive + best-effort. Never auto-prefilled (the UI only
        // promotes confident `wd` tags), so these surface as click-to-add chips.
        // Gate on the key's presence (the service sets `zeroshot` only when it ran), and
        // store even an empty set so a re-run with no matches clears stale `clip` pending.
        $zeroshot = $result['zeroshot'] ?? null;
        if (is_array($zeroshot)) {
            try {
                $tags = array_map(
                    static fn (array $match): array => ['name' => $match['name'], 'score' => (float) ($match['score'] ?? 0.0), 'category' => null],
                    $zeroshot,
                );
                $this->suggestionService->store($message->targetType, $message->id, ['tags' => $tags, 'rating' => ['label' => null]], TagSuggestion::SOURCE_CLIP);
            } catch (\Throwable $exception) {
                $this->logger->warning('automatic tagging zero-shot storage failed', ['target' => $message->targetType, 'id' => $message->id, 'error' => $exception->getMessage()]);
            }
        }

        // Semantic embedding is additive + best-effort: isolate it so a dimension
        // mismatch or DB error logs to the autotag channel and never poisons the worker
        // (nor blocks the tag suggestions stored above).
        $embedding = $result['embedding'] ?? null;
        if (is_array($embedding) && $embedding !== []) {
            $clipVector = '['.implode(',', array_map('floatval', $embedding)).']';
            $embeddingModelId = $result['clip_model_id'] ?? $clipModelId;
            try {
                $item->setClipVector($clipVector)->setClipModelId($embeddingModelId);
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                $this->logger->warning('automatic tagging embedding storage failed', ['target' => $message->targetType, 'id' => $message->id, 'error' => $exception->getMessage()]);

                return; // embedding not stored — don't run kNN on an unstored vector
            }

            // Learned suggestions from the nearest already-tagged items. Best-effort:
            // a failure here must not block the WD suggestions already stored above.
            try {
                $this->knnSuggestionService->propagate($message->targetType, $message->id, $clipVector, (string) $embeddingModelId);
            } catch (\Throwable $exception) {
                $this->logger->warning('automatic tagging kNN propagation failed', ['target' => $message->targetType, 'id' => $message->id, 'error' => $exception->getMessage()]);
            }
        }
    }
}

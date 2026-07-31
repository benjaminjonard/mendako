<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateSuggestionsMessage;
use App\Repository\PostRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\AutoTag\FrameResultAggregator;
use App\Service\AutoTag\SuggestionService;
use App\Service\ThumbnailGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async tagging handler: produces tag suggestions via the inference service (persisted as
 * non-authoritative TagSuggestions, never tags). The post's board decides which models run — WD for
 * illustrations, RAM++ for photographs, or both — and each model's output is stored under its own
 * source. Idempotent and feature-gated.
 */
#[AsMessageHandler]
final class GenerateSuggestionsHandler
{
    // Number of frames sampled from a video for content tagging.
    private const int VIDEO_FRAME_COUNT = 5;

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        private readonly AutoTagInferenceClient $autoTagInferenceClient,
        private readonly SuggestionService $suggestionService,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly FrameResultAggregator $frameResultAggregator,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath,
        #[Autowire(service: 'monolog.logger.autotag')] private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateSuggestionsMessage $message): void
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return;
        }

        $post = $this->postRepository->find($message->id);
        if ($post === null || $post->getPath() === null) {
            return; // removed before processing — idempotent no-op
        }

        // Resolved here rather than at dispatch time, so a config change applies to queued messages.
        $models = $this->autoTagConfigProvider->getModelsForBoard($post->getBoard()?->getSlug());
        if ($models === []) {
            $this->logger->info('No model configured for this post\'s board; skipping tagging', ['id' => $message->id]);

            return;
        }

        $sourcePath = $this->publicPath.'/'.$post->getPath();
        $isVideo = in_array($post->getMimetype(), ThumbnailGenerator::VIDEO_MIMETYPES, true);
        $tempDir = null;
        $thumbnail = null;
        try {
            // One decode shared by every model: sample the frames (video) or build the single
            // representative thumbnail (image) once, then run each model over the same input.
            $frames = [];
            if ($isVideo) {
                $tempDir = sys_get_temp_dir().'/mendako-autotag-'.bin2hex(random_bytes(8));
                $frames = $this->thumbnailGenerator->extractVideoFrames($sourcePath, $tempDir, self::VIDEO_FRAME_COUNT, 600);
                if ($frames === []) {
                    // A video the sampler couldn't read still gets the single-frame treatment.
                    $this->logger->warning('No video frames extracted; falling back to a single frame', ['id' => $message->id]);
                }
            }

            if ($frames === []) {
                // Image, or the video fallback: one representative thumbnail (generate() reads videos too).
                $thumbnail = sys_get_temp_dir().'/mendako-autotag-'.bin2hex(random_bytes(8)).'.jpeg';
                $this->thumbnailGenerator->generate($sourcePath, $thumbnail, 600, 'jpeg');
            }

            $results = [];
            foreach ($models as $source => $modelId) {
                $results[$source] = $this->analyzeWith($modelId, $frames, $thumbnail);
            }
        } catch (\Throwable $exception) {
            // Soft-fail: a bad/missing source image must not poison the worker.
            $this->logger->warning('automatic tagging failed', ['id' => $message->id, 'error' => $exception->getMessage()]);

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

        foreach ($results as $source => $result) {
            if (empty($result['tags']) && ($result['rating']['label'] ?? null) === null) {
                continue;
            }

            // Per-model isolation: one model's storage failure must not cost the other its results.
            try {
                $this->suggestionService->store('post', $message->id, $result, $source);
            } catch (\Throwable $exception) {
                $this->logger->warning('storing automatic tagging suggestions failed', [
                    'id' => $message->id,
                    'source' => $source,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $this->logger->info('automatic tagging analysis stored suggestions', [
                'id' => $message->id,
                'source' => $source,
                'tag_count' => count($result['tags'] ?? []),
                'rating' => $result['rating']['label'] ?? null,
            ]);
        }
    }

    /**
     * Run one model over the already-decoded input: the sampled video frames, whose tags are
     * aggregated into a single set, or the one representative thumbnail.
     *
     * @param string[] $frames
     */
    private function analyzeWith(string $modelId, array $frames, ?string $thumbnail): array
    {
        if ($frames === []) {
            return $this->autoTagInferenceClient->analyze((string) $thumbnail, $modelId);
        }

        return $this->frameResultAggregator->aggregate(array_map(
            fn (string $framePath): array => $this->autoTagInferenceClient->analyze($framePath, $modelId),
            $frames,
        ));
    }
}

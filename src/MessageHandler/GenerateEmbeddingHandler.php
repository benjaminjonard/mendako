<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateEmbeddingMessage;
use App\Repository\EmbeddingRepository;
use App\Repository\PostRepository;
use App\Repository\StagedUploadRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\ThumbnailGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Computes + stores one item's embedding(s) (embedding pool) — no tagging, no kNN. Fills the
 * pool the kNN "learned" suggestions (and, later, a trained classifier) read from.
 *
 * An image yields one embedding; a video yields one per sampled frame (so kNN can match on any
 * frame). Idempotent (replaces the target's rows) and feature-gated; soft-fails so a bad source
 * image never poisons the worker.
 */
#[AsMessageHandler]
final class GenerateEmbeddingHandler
{
    // Frames sampled from a video (mirrors the tagging pipeline's VIDEO_FRAME_COUNT).
    private const int VIDEO_FRAME_COUNT = 5;

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly StagedUploadRepository $stagedUploadRepository,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        private readonly AutoTagInferenceClient $autoTagInferenceClient,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly EmbeddingRepository $embeddingRepository,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath,
        #[Autowire(service: 'monolog.logger.autotag')] private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateEmbeddingMessage $message): void
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return;
        }

        $modelId = $this->autoTagConfigProvider->getActiveModel('wd');
        if ($modelId === null) {
            return; // no embedding encoder configured
        }

        $item = $message->targetType === 'staged'
            ? $this->stagedUploadRepository->find($message->id)
            : $this->postRepository->find($message->id);

        if ($item === null || $item->getPath() === null) {
            return; // removed before processing — idempotent no-op
        }

        $sourcePath = $this->publicPath.'/'.$item->getPath();
        $isVideo = in_array($item->getMimetype(), ThumbnailGenerator::VIDEO_MIMETYPES, true);
        $tempDir = null;
        $thumbnail = null;
        $vectors = [];

        try {
            if ($isVideo) {
                // One embedding per sampled frame (evenly across the video).
                $tempDir = sys_get_temp_dir().'/mendako-embed-'.bin2hex(random_bytes(8));
                foreach ($this->thumbnailGenerator->extractVideoFrames($sourcePath, $tempDir, self::VIDEO_FRAME_COUNT, 600) as $framePath) {
                    $this->collectEmbedding($vectors, $framePath, $modelId);
                }
            }

            if ($vectors === []) {
                // Image, or a video whose frames couldn't be sampled: one representative thumbnail.
                $thumbnail = sys_get_temp_dir().'/mendako-embed-'.bin2hex(random_bytes(8)).'.jpeg';
                $this->thumbnailGenerator->generate($sourcePath, $thumbnail, 600, 'jpeg');
                if (is_file($thumbnail)) {
                    $this->collectEmbedding($vectors, $thumbnail, $modelId);
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('embedding generation failed', ['target' => $message->targetType, 'id' => $message->id, 'error' => $exception->getMessage()]);

            return;
        } finally {
            if ($thumbnail !== null) {
                @unlink($thumbnail);
            }
            if ($tempDir !== null && is_dir($tempDir)) {
                foreach (glob($tempDir.'/*') ?: [] as $frameFile) {
                    @unlink($frameFile);
                }
                @rmdir($tempDir);
            }
        }

        if ($vectors === []) {
            return; // nothing produced (e.g. SVG, or the service was unreachable) — soft no-op
        }

        try {
            $this->embeddingRepository->replaceForTarget($message->targetType, $message->id, $modelId, $vectors);
        } catch (\Throwable $exception) {
            $this->logger->warning('embedding storage failed', ['target' => $message->targetType, 'id' => $message->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * @param string[] $vectors mutated in place: appends the frame's pgvector literal if the
     *                          service returned a usable embedding
     */
    private function collectEmbedding(array &$vectors, string $imagePath, string $modelId): void
    {
        $embedding = $this->autoTagInferenceClient->embed($imagePath, $modelId)['embedding'] ?? null;
        if (is_array($embedding) && $embedding !== []) {
            $vectors[] = '['.implode(',', array_map('floatval', $embedding)).']';
        }
    }
}

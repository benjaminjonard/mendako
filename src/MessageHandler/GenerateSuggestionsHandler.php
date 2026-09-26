<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateSuggestionsMessage;
use App\Repository\PostRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\AutoTag\AutoTagInferenceException;
use App\Service\AutoTag\FrameResultAggregator;
use App\Service\AutoTag\SuggestionService;
use App\Service\ThumbnailGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateSuggestionsHandler
{
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
            return;
        }

        $models = $this->autoTagConfigProvider->getModelsForBoard($post->getBoard()?->getSlug());
        if ($models === []) {
            $this->logger->info('No model configured for this post\'s board; skipping tagging', ['id' => $message->id]);

            return;
        }

        $sourcePath = $this->publicPath.'/'.$post->getPath();
        $isVideo = in_array($post->getMimetype(), ThumbnailGenerator::VIDEO_MIMETYPES, true);
        $tempDir = null;
        $thumbnail = null;
        $generated = null;
        try {
            $frames = [];
            if ($isVideo) {
                $tempDir = sys_get_temp_dir().'/mendako-autotag-'.bin2hex(random_bytes(8));
                $frames = $this->thumbnailGenerator->extractVideoFrames($sourcePath, $tempDir, self::VIDEO_FRAME_COUNT, 600);
                if ($frames === []) {
                    $this->logger->warning('No video frames extracted; falling back to a single frame', ['id' => $message->id]);
                }
            }

            if ($frames === [] && !$isVideo) {
                $thumbnail = $sourcePath;
            } elseif ($frames === []) {
                $generated = sys_get_temp_dir().'/mendako-autotag-'.bin2hex(random_bytes(8)).'.jpeg';
                $this->thumbnailGenerator->generate($sourcePath, $generated, 600, 'jpeg');
                $thumbnail = $generated;
            }

            $results = [];
            foreach ($models as $source => $modelId) {
                $results[$source] = $this->analyzeWith($modelId, $frames, $thumbnail);
            }
        } catch (AutoTagInferenceException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->warning('automatic tagging failed', ['id' => $message->id, 'error' => $exception->getMessage()]);

            return;
        } finally {
            if ($generated !== null) {
                @unlink($generated);
            }
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

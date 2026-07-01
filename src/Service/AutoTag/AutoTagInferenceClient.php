<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The single HTTP boundary between the app and the inference service.
 *
 * Every call soft-fails: on a disabled feature, unreachable service, timeout, or
 * non-2xx response it returns a neutral/empty value and logs — it NEVER throws
 * into a web request. Models are baked into the service image, so there is no
 * download step: a model is always ready.
 */
class AutoTagInferenceClient
{
    // Inference (tags + embedding + zero-shot over the vocabulary) is heavy and runs
    // on the async worker — allow a generous timeout, especially for the cold-start
    // load of the large CLIP encoders on the first request.
    private const float ANALYZE_TIMEOUT_SECONDS = 180.0;
    // Vocabulary calls are lightweight: the service starts the warm-up in the background and
    // replies immediately, and the status read is trivial.
    private const float VOCABULARY_TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        #[Autowire(service: 'monolog.logger.autotag')] private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ask the service to encode the given tag vocabulary into its persistent cache (a one-off,
     * runs in the background there). reset=true wipes the cache first and re-encodes everything;
     * otherwise only the missing tags are encoded. Fire-and-forget: soft-fails like every call.
     *
     * @param string[] $tags
     */
    public function warmVocabulary(string $clipModelId, array $tags, bool $reset = false): void
    {
        if (!$this->autoTagConfigProvider->isEnabled() || $tags === []) {
            return;
        }

        $url = rtrim($this->autoTagConfigProvider->getServiceUrl(), '/').'/vocabulary';

        try {
            $this->httpClient->request('POST', $url, [
                'timeout' => self::VOCABULARY_TIMEOUT_SECONDS,
                'json' => ['model' => $clipModelId, 'tags' => array_values($tags), 'reset' => $reset],
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Service vocabulary warm-up call failed', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Coverage of the given tags in the service's text cache:
     * ['cached' => int, 'missing' => int, 'total' => int]. Cheap (no model load). [] on failure.
     *
     * @param string[] $tags
     *
     * @return array<string, mixed>
     */
    public function vocabularyMissing(string $clipModelId, array $tags): array
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return [];
        }

        $url = rtrim($this->autoTagConfigProvider->getServiceUrl(), '/').'/vocabulary/missing';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'timeout' => self::VOCABULARY_TIMEOUT_SECONDS,
                'json' => ['model' => $clipModelId, 'tags' => array_values($tags)],
            ]);
            if ($response->getStatusCode() !== 200) {
                return [];
            }

            return $response->toArray();
        } catch (\Throwable $exception) {
            $this->logger->warning('Service vocabulary missing call failed', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    /**
     * Progress of the vocabulary warm-up: ['running' => bool, 'done' => int, 'total' => int].
     * Returns [] on any failure.
     *
     * @return array<string, mixed>
     */
    public function vocabularyStatus(): array
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return [];
        }

        $url = rtrim($this->autoTagConfigProvider->getServiceUrl(), '/').'/vocabulary';

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => self::VOCABULARY_TIMEOUT_SECONDS]);
            if ($response->getStatusCode() !== 200) {
                return [];
            }

            return $response->toArray();
        } catch (\Throwable $exception) {
            $this->logger->warning('Service vocabulary status call failed', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    /**
     * Run base-model inference on an image. Returns `{tags, rating}` (plus `embedding`
     * and `zeroshot` when a CLIP model is active) or [] on any failure.
     *
     * @param string[] $tagNames the user's tag vocabulary for zero-shot scoring
     *
     * @return array<string, mixed>
     */
    public function analyze(string $imagePath, string $modelId, ?string $clipModelId = null, array $tagNames = []): array
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return [];
        }

        $url = rtrim($this->autoTagConfigProvider->getServiceUrl(), '/').'/analyze';

        try {
            $fields = [
                'model' => $modelId,
                'image' => DataPart::fromPath($imagePath),
            ];
            // Fold the CLIP embedding into the same call when a model is active.
            if ($clipModelId !== null) {
                $fields['clip_model'] = $clipModelId;
                // Zero-shot against the user's own tag names (text-encoded by the service).
                if ($tagNames !== []) {
                    $fields['tag_names'] = json_encode(array_values($tagNames));
                }
            }
            $formData = new FormDataPart($fields);
            $response = $this->httpClient->request('POST', $url, [
                'timeout' => self::ANALYZE_TIMEOUT_SECONDS,
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ]);

            if (!$this->isSuccessful($response->getStatusCode())) {
                $this->logger->warning('Service /analyze returned a non-2xx status', ['status' => $response->getStatusCode()]);

                return [];
            }

            return $response->toArray();
        } catch (\Throwable $exception) {
            $this->logger->warning('Service /analyze call failed', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    private function isSuccessful(int $statusCode): bool
    {
        return $statusCode >= 200 && $statusCode < 300;
    }
}

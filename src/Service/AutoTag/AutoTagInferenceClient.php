<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The single HTTP boundary between the app and the inference service. Every call soft-fails: on a
 * disabled feature, unreachable service, timeout, or non-2xx response it returns an empty value and
 * logs — it NEVER throws into a web request.
 */
class AutoTagInferenceClient
{
    // Inference (tags + embedding) is heavy and runs on the async worker — allow a generous
    // timeout, especially for the cold-start load of the WD model on the first request.
    private const float ANALYZE_TIMEOUT_SECONDS = 180.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        #[Autowire(service: 'monolog.logger.autotag')] private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run WD inference on an image. Returns `{tags, rating}` plus `embedding` / `embedding_dim`
     * / `embedding_model_id` (WD's fc_norm feature, produced in the same pass), or [] on failure.
     */
    public function analyze(string $imagePath, string $modelId): array
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return [];
        }

        $url = rtrim($this->autoTagConfigProvider->getServiceUrl(), '/').'/analyze';

        try {
            $formData = new FormDataPart([
                'model' => $modelId,
                'image' => DataPart::fromPath($imagePath),
            ]);
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

    /**
     * Image embedding only (embedding-pool prefill), no tagging. Returns
     * ['embedding' => float[], 'dim' => int, 'model_id' => string] or [] on any failure.
     */
    public function embed(string $imagePath, string $modelId): array
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            return [];
        }

        $url = rtrim($this->autoTagConfigProvider->getServiceUrl(), '/').'/embed';

        try {
            $formData = new FormDataPart([
                'model' => $modelId,
                'image' => DataPart::fromPath($imagePath),
            ]);
            $response = $this->httpClient->request('POST', $url, [
                'timeout' => self::ANALYZE_TIMEOUT_SECONDS,
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ]);

            if (!$this->isSuccessful($response->getStatusCode())) {
                $this->logger->warning('Service /embed returned a non-2xx status', ['status' => $response->getStatusCode()]);

                return [];
            }

            return $response->toArray();
        } catch (\Throwable $exception) {
            $this->logger->warning('Service /embed call failed', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    private function isSuccessful(int $statusCode): bool
    {
        return $statusCode >= 200 && $statusCode < 300;
    }
}

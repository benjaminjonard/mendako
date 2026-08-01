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
 */
class AutoTagInferenceClient
{
    // Inference is heavy and runs on the async worker — allow a generous timeout,
    // especially for the cold-start load of the WD model on the first request.
    private const float ANALYZE_TIMEOUT_SECONDS = 180.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        #[Autowire(service: 'monolog.logger.autotag')] private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run WD inference on an image. Returns `{tags, rating}`.
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

                throw new AutoTagInferenceException(sprintf('Service /analyze returned status %d', $response->getStatusCode()));
            }

            return $response->toArray();
        } catch (AutoTagInferenceException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->warning('Service /analyze call failed', ['error' => $exception->getMessage()]);

            throw new AutoTagInferenceException($exception->getMessage(), 0, $exception);
        }
    }

    private function isSuccessful(int $statusCode): bool
    {
        return $statusCode >= 200 && $statusCode < 300;
    }
}

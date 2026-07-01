<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateVectorMessage;
use App\Repository\PostRepository;
use App\Service\PostVectorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * (Re)computes + stores one post's perceptual duplicate-detection vector via PostVectorService
 * (pure PHP/GD, no ML call), so it works whether or not auto-tagging is enabled. Idempotent
 * (overwrites the target's vector) and soft-fails so a bad source image never poisons the worker.
 */
#[AsMessageHandler]
final class GenerateVectorHandler
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly PostVectorService $postVectorService,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath,
        #[Autowire(service: 'monolog.logger.autotag')] private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateVectorMessage $message): void
    {
        $post = $this->postRepository->find($message->id);
        if ($post === null || $post->getPath() === null) {
            return; // removed before processing — idempotent no-op
        }

        $path = $this->publicPath.'/'.$post->getPath();
        if (!is_file($path)) {
            return;
        }

        try {
            $vector = $this->postVectorService->generateVector(new File($path));
        } catch (\Throwable $e) {
            $this->logger->warning('Duplicate-detection vector recompute failed', [
                'post' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $post->setVector($vector);
        $this->entityManager->flush();
    }
}

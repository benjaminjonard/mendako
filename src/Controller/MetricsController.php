<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Service\DiskUsageCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Annotation\Route;

class MetricsController extends AbstractController
{
    private array $lines = [];

    #[Route(
        path: '/metrics',
        name: 'metrics_index',
        methods: ['GET']
    )]
    public function index(
        #[Autowire('%env(APP_ENABLE_METRICS)%')] bool $enableMetrics,
        #[Autowire('%kernel.project_dir%/public/uploads')] string $uploadsPath,
        UserRepository $userRepository,
        BoardRepository $boardRepository,
        PostRepository $postRepository,
        TagRepository $tagRepository,
        DiskUsageCalculator $diskUsageCalculator
    ): Response
    {
        if ($enableMetrics !== true) {
            throw new AccessDeniedHttpException();
        }

        $this->addCounter('user', [['label' => '', 'value' => $userRepository->count([])]], 'number of registered users');

        // Create global counters
        $boardValues[] = ['label' => null, 'value' => $boardRepository->count([])];
        $postValues[] = ['label' => null, 'value' => $postRepository->count([])];
        $tagValues[] = ['label' => null, 'value' => $tagRepository->count([])];
        $diskUsedValues[] = ['label' => null, 'value' => $diskUsageCalculator->getFolderSize($uploadsPath)];

        // Create counters per board
        foreach ($boardRepository->findAll() as $board) {
            $label = "{board=\"{$board->getName()}\"}";
            $postValues[] = ['label' => $label, 'value' => $postRepository->count(['board' => $board])];
            $diskUsedValues[] = ['label' => $label, 'value' => $diskUsageCalculator->getFolderSize("{$uploadsPath}/boards/{$board->getId()}")];
        }

        // Fill metrics
        $this->addCounter('board', $boardValues, 'number of created boards');
        $this->addCounter('post', $postValues, 'number of created posts');
        $this->addCounter('tag', $tagValues, 'number of created tags');
        $this->addCounter('used_disk_space_bytes', $diskUsedValues, 'used disk space by uploads (images, videos, files)', 'bytes');

        $metrics = implode(PHP_EOL, $this->lines);
        $response = new Response();
        $response->setContent($metrics);
        $response->headers->set('Content-Type', 'text/plain');

        return $response;
    }

    public function addCounter(string $name, array $values, string $help, string $unit = null): void
    {
        //$name = "mendako_{$name}";

        $this->lines[] = "# HELP {$name} {$help}";
        if ($unit !== null) {
            $this->lines[] = "# UNIT {$name} {$unit}";
        }
        $this->lines[] = "# TYPE {$name} counter";

        foreach ($values as $value) {
            $this->lines[] = "{$name}_total{$value['label']} {$value['value']}";
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\EnqueueThumbnailBacklogMessage;
use App\Message\EnqueueVectorBacklogMessage;
use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\StagedPostRepository;
use App\Service\DiskUsageCalculator;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private const array VECTOR_CLASSES = ['EnqueueVectorBacklogMessage', 'GenerateVectorMessage'];
    private const array THUMBNAIL_CLASSES = ['EnqueueThumbnailBacklogMessage', 'GenerateThumbnailMessage'];

    private const array JOB_CLASSES = [
        'vectors' => self::VECTOR_CLASSES,
        'thumbnails' => self::THUMBNAIL_CLASSES,
    ];

    private const string QUEUE = 'batch';

    private const string MESSENGER_TABLE = 'doctrine_messenger_message';

    // A message reserved by a worker longer than this is treated as abandoned, not in flight.
    private const int STALE_RESERVED_SECONDS = 300;

    #[Route(path: '/admin', name: 'app_admin_index', methods: ['GET', 'POST'])]
    public function index(
        DiskUsageCalculator $diskUsageCalculator,
        BoardRepository $boardRepository,
        PostRepository $postRepository,
        #[Autowire('%release%')] string $release,
        #[Autowire('%kernel.project_dir%/public/uploads')] string $uploadsPath,
        #[Autowire('%env(APP_THUMBNAILS_FORMAT)%')] string $thumbnailsFormat,
        #[Autowire('%env(APP_POST_PER_PAGE)%')] int $postPerPage,
        #[Autowire('%env(APP_INFINITE_SCROLL_POST_PER_PAGE)%')] int $infiniteScrollPostPerPage
    ): Response
    {
        return $this->render('App/Admin/index.html.twig', [
            'release' => $release,
            'thumbnailsFormat' => $thumbnailsFormat,
            'postPerPage' => $postPerPage,
            'infiniteScrollPostPerPage' => $infiniteScrollPostPerPage,
            'diskUsage' => $diskUsageCalculator->getFolderSize($uploadsPath) + $diskUsageCalculator->getFolderSize($uploadsPath),
            'posts' => $postRepository->count([]),
            'boards' => $boardRepository->count([]),
            'phpVersion' => phpversion(),
            'symfonyVersion' => Kernel::VERSION,
            'frankenPhpVersion' => \extension_loaded('frankenphp') ? str_replace('v', '', phpversion('frankenphp')) : null,
        ]);
    }

    #[Route(path: '/admin/jobs', name: 'app_admin_jobs', methods: ['GET'])]
    public function jobs(): Response
    {
        return $this->render('App/Admin/jobs.html.twig');
    }

    #[Route(path: '/admin/jobs/status', name: 'app_admin_jobs_status', methods: ['GET'])]
    public function jobsStatus(PostRepository $postRepository, StagedPostRepository $stagedPostRepository, BoardRepository $boardRepository, Connection $connection, TranslatorInterface $translator): JsonResponse
    {
        $total = $postRepository->countAll();

        $vectors = $this->buildJobStatus(
            $translator,
            $connection,
            self::VECTOR_CLASSES,
            $total - $postRepository->countWithoutVector(),
            $total,
            'label.vectors_done',
            'label.vectors_todo',
        );

        $thumbnailTotal = $total + $stagedPostRepository->countAll() + $boardRepository->countWithCover();
        $thumbnailRemaining = $postRepository->countWithoutThumbnail()
            + $stagedPostRepository->countWithoutThumbnail()
            + $boardRepository->countWithoutThumbnail();

        $thumbnails = $this->buildJobStatus(
            $translator,
            $connection,
            self::THUMBNAIL_CLASSES,
            $thumbnailTotal - $thumbnailRemaining,
            $thumbnailTotal,
            'label.thumbnails_done',
            'label.thumbnails_todo',
        );

        return $this->json([
            'vectors' => $vectors,
            'thumbnails' => $thumbnails,
        ]);
    }

    #[Route(path: '/admin/jobs/thumbnails', name: 'app_admin_jobs_thumbnails', methods: ['POST'])]
    public function thumbnailBacklog(Request $request, MessageBusInterface $messageBus, TranslatorInterface $translator, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('jobs_thumbnails', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if ($this->pendingCounts($connection, self::THUMBNAIL_CLASSES)['pending'] > 0) {
            $this->addFlash('notice', $translator->trans('message.job_already_running'));

            return $this->redirectToRoute('app_admin_jobs');
        }

        $messageBus->dispatch(
            new EnqueueThumbnailBacklogMessage($request->request->getBoolean('all')),
            [new TransportNamesStamp(self::QUEUE)],
        );
        $this->addFlash('notice', $translator->trans('message.thumbnails_started'));

        return $this->redirectToRoute('app_admin_jobs');
    }

    #[Route(path: '/admin/jobs/vectors', name: 'app_admin_jobs_vectors', methods: ['POST'])]
    public function vectorBacklog(Request $request, MessageBusInterface $messageBus, TranslatorInterface $translator, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('jobs_vectors', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if ($this->pendingCounts($connection, self::VECTOR_CLASSES)['pending'] > 0) {
            $this->addFlash('notice', $translator->trans('message.job_already_running'));

            return $this->redirectToRoute('app_admin_jobs');
        }

        $messageBus->dispatch(
            new EnqueueVectorBacklogMessage($request->request->getBoolean('all')),
            [new TransportNamesStamp(self::QUEUE)],
        );
        $this->addFlash('notice', $translator->trans('message.vectors_started'));

        return $this->redirectToRoute('app_admin_jobs');
    }

    #[Route(path: '/admin/jobs/{job}/cancel', name: 'app_admin_jobs_cancel', methods: ['POST'])]
    public function cancelJob(string $job, Request $request, TranslatorInterface $translator, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('job_cancel', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $messageClasses = self::JOB_CLASSES[$job] ?? null;
        if (null === $messageClasses) {
            throw $this->createNotFoundException(\sprintf('Unknown job "%s".', $job));
        }

        $this->cancelPending($connection, $messageClasses);
        $this->addFlash('notice', $translator->trans('message.job_cancelled'));

        return $this->redirectToRoute('app_admin_jobs');
    }

    private function buildJobStatus(TranslatorInterface $translator, Connection $connection, array $messageClasses, int $processed, int $total, string $doneKey, string $todoKey): array
    {
        $counts = $this->pendingCounts($connection, $messageClasses);
        // The first class of each job is its coordinator: it fans the work out into one message per item.
        $coordinatorCounts = $this->pendingCounts($connection, [$messageClasses[0]]);

        $fannedOut = $coordinatorCounts['pending'] === 0;
        $starting = $coordinatorCounts['delivered'] > 0;
        $running = $fannedOut && $counts['delivered'] > 0;
        $waiting = !$starting && !$running && $counts['pending'] > 0;
        $remaining = max($total - $processed, 0);

        $showBar = $fannedOut && $counts['pending'] > 0;
        $barProcessed = $showBar ? max($total - $counts['pending'], 0) : $processed;

        return [
            'processed' => $barProcessed,
            'total' => $total,
            'running' => $counts['pending'] > 0,
            'state' => match (true) {
                $starting => 'starting',
                $running => 'running',
                $waiting => 'waiting',
                $remaining > 0 => 'partial',
                default => 'done',
            },
            'label' => match (true) {
                $starting => $translator->trans('label.run_starting'),
                $running => \sprintf('%s — %s %s', $translator->trans('label.run_active'), number_format($counts['pending']), $translator->trans('label.run_remaining')),
                $waiting => $translator->trans('label.run_waiting'),
                $remaining > 0 => \sprintf('%s %s · %s %s', number_format($processed), $translator->trans($doneKey), number_format($remaining), $translator->trans($todoKey)),
                default => \sprintf('%s %s', number_format($processed), $translator->trans($doneKey)),
            },
            'showBar' => $showBar,
        ];
    }

    private function pendingCounts(Connection $connection, array $messageClasses): array
    {
        try {
            if (!$connection->createSchemaManager()->tablesExist([self::MESSENGER_TABLE])) {
                return ['pending' => 0, 'delivered' => 0];
            }

            [$clause, $params] = $this->bodyLikeFilter($messageClasses);
            $row = $connection->fetchAssociative(
                \sprintf(
                    "SELECT
                        COUNT(*) FILTER (WHERE delivered_at IS NULL OR delivered_at >= NOW() - INTERVAL '%d seconds') AS pending,
                        COUNT(*) FILTER (WHERE delivered_at >= NOW() - INTERVAL '%d seconds') AS delivered
                     FROM %s WHERE queue_name = ? AND (%s)",
                    self::STALE_RESERVED_SECONDS,
                    self::STALE_RESERVED_SECONDS,
                    self::MESSENGER_TABLE,
                    $clause,
                ),
                [self::QUEUE, ...$params],
            );

            return [
                'pending' => (int) ($row['pending'] ?? 0),
                'delivered' => (int) ($row['delivered'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['pending' => 0, 'delivered' => 0];
        }
    }

    private function cancelPending(Connection $connection, array $messageClasses): int
    {
        try {
            if (!$connection->createSchemaManager()->tablesExist([self::MESSENGER_TABLE])) {
                return 0;
            }

            [$clause, $params] = $this->bodyLikeFilter($messageClasses);

            return (int) $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE queue_name = ? AND delivered_at IS NULL AND (%s)', self::MESSENGER_TABLE, $clause),
                [self::QUEUE, ...$params],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    private function bodyLikeFilter(array $messageClasses): array
    {
        $clause = implode(' OR ', array_fill(0, \count($messageClasses), 'body LIKE ?'));
        $params = array_map(static fn (string $class): string => '%'.$class.'%', $messageClasses);

        return [$clause, $params];
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\EnqueueBacklogMessage;
use App\Message\EnqueueThumbnailBacklogMessage;
use App\Message\EnqueueVectorBacklogMessage;
use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\StagedPostRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
class AutoTagConfigController extends AbstractController
{
    private const TAGGING_CLASSES = ['EnqueueBacklogMessage', 'GenerateSuggestionsMessage'];
    private const VECTOR_CLASSES = ['EnqueueVectorBacklogMessage', 'GenerateVectorMessage'];
    private const THUMBNAIL_CLASSES = ['EnqueueThumbnailBacklogMessage', 'GenerateThumbnailMessage'];

    private const JOB_CLASSES = [
        'tagging' => self::TAGGING_CLASSES,
        'vectors' => self::VECTOR_CLASSES,
        'thumbnails' => self::THUMBNAIL_CLASSES,
    ];

    private const int STALE_RESERVED_SECONDS = 300;

    #[Route(path: '/admin/autotag/tag-backlog', name: 'app_autotag_tag_backlog', methods: ['POST'])]
    public function tagBacklog(Request $request, AutoTagConfigProvider $autoTagConfigProvider, MessageBusInterface $messageBus, TranslatorInterface $translator, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('autotag_batch', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if (!$autoTagConfigProvider->isEnabled()) {
            throw $this->createNotFoundException();
        }

        if ($this->pendingCounts($connection, self::TAGGING_CLASSES)['pending'] > 0) {
            $this->addFlash('notice', $translator->trans('message.job_already_running'));

            return $this->redirectToRoute('app_admin_jobs');
        }

        $messageBus->dispatch(
            new EnqueueBacklogMessage($request->request->getBoolean('all')),
            [new TransportNamesStamp('autotag_batch')],
        );
        $this->addFlash('notice', $translator->trans('message.automatic_tags_started'));

        return $this->redirectToRoute('app_admin_jobs');
    }

    #[Route(path: '/admin/autotag/jobs', name: 'app_autotag_jobs', methods: ['GET'])]
    public function jobs(PostRepository $postRepository, StagedPostRepository $stagedPostRepository, BoardRepository $boardRepository, AutoTagConfigProvider $autoTagConfigProvider, Connection $connection, TranslatorInterface $translator): JsonResponse
    {
        $total = $postRepository->countAll();

        $enabledSlugs = $autoTagConfigProvider->getEnabledBoardSlugs();
        if (in_array('*', $enabledSlugs, true)) {
            $taggingTotal = $total;
            $taggingProcessed = $total - $postRepository->countWithoutSuggestions();
        } elseif ($enabledSlugs === []) {
            $taggingTotal = 0;
            $taggingProcessed = 0;
        } else {
            $taggingTotal = $postRepository->countOnBoards($enabledSlugs);
            $taggingProcessed = $taggingTotal - $postRepository->countWithoutSuggestionsOnBoards($enabledSlugs);
        }

        $tagging = $this->buildJobStatus(
            $translator,
            $connection,
            self::TAGGING_CLASSES,
            $taggingProcessed,
            $taggingTotal,
            'label.tagging_done',
            'label.tagging_todo',
        );

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
            'tagging' => $tagging,
            'vectors' => $vectors,
            'thumbnails' => $thumbnails,
        ]);
    }

    #[Route(path: '/admin/thumbnails/backlog', name: 'app_thumbnails_backlog', methods: ['POST'])]
    public function thumbnailBacklog(Request $request, MessageBusInterface $messageBus, TranslatorInterface $translator, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('autotag_thumbnails', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if ($this->pendingCounts($connection, self::THUMBNAIL_CLASSES)['pending'] > 0) {
            $this->addFlash('notice', $translator->trans('message.job_already_running'));

            return $this->redirectToRoute('app_admin_jobs');
        }

        $messageBus->dispatch(
            new EnqueueThumbnailBacklogMessage($request->request->getBoolean('all')),
            [new TransportNamesStamp('autotag_batch')],
        );
        $this->addFlash('notice', $translator->trans('message.thumbnails_started'));

        return $this->redirectToRoute('app_admin_jobs');
    }

    private function buildJobStatus(TranslatorInterface $translator, Connection $connection, array $messageClasses, int $processed, int $total, string $doneKey, string $todoKey): array
    {
        $counts = $this->pendingCounts($connection, $messageClasses);
        $coordinatorCounts = $this->coordinatorCounts($connection, $messageClasses);

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
            'running' => $counts['pending'] > 0, // queued OR in flight (coordinator or items) — keep launch buttons disabled
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

    private function coordinatorCounts(Connection $connection, array $messageClasses): array
    {
        return $this->pendingCounts($connection, [$messageClasses[0]]);
    }

    #[Route(path: '/admin/vectors/backlog', name: 'app_vectors_backlog', methods: ['POST'])]
    public function vectorBacklog(Request $request, MessageBusInterface $messageBus, TranslatorInterface $translator, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('autotag_vectors', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if ($this->pendingCounts($connection, self::VECTOR_CLASSES)['pending'] > 0) {
            $this->addFlash('notice', $translator->trans('message.job_already_running'));

            return $this->redirectToRoute('app_admin_jobs');
        }

        $messageBus->dispatch(
            new EnqueueVectorBacklogMessage($request->request->getBoolean('all')),
            [new TransportNamesStamp('autotag_batch')],
        );
        $this->addFlash('notice', $translator->trans('message.vectors_started'));

        return $this->redirectToRoute('app_admin_jobs');
    }

    #[Route(path: '/admin/jobs/{job}/cancel', name: 'app_jobs_cancel', methods: ['POST'])]
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

    private function pendingCounts(Connection $connection, array $messageClasses): array
    {
        try {
            if (!$connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return ['pending' => 0, 'delivered' => 0];
            }

            [$clause, $params] = $this->bodyLikeFilter($messageClasses);
            $row = $connection->fetchAssociative(
                \sprintf(
                    "SELECT
                        COUNT(*) FILTER (WHERE delivered_at IS NULL OR delivered_at >= NOW() - INTERVAL '%d seconds') AS pending,
                        COUNT(*) FILTER (WHERE delivered_at >= NOW() - INTERVAL '%d seconds') AS delivered
                     FROM messenger_messages WHERE queue_name = ? AND (%s)",
                    self::STALE_RESERVED_SECONDS,
                    self::STALE_RESERVED_SECONDS,
                    $clause,
                ),
                ['autotag_batch', ...$params],
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
            if (!$connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return 0;
            }

            [$clause, $params] = $this->bodyLikeFilter($messageClasses);

            return (int) $connection->executeStatement(
                \sprintf('DELETE FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NULL AND (%s)', $clause),
                ['autotag_batch', ...$params],
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

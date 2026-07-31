<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\EnqueueBacklogMessage;
use App\Message\EnqueueVectorBacklogMessage;
use App\Repository\PostRepository;
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

/**
 * Automatic tagging actions, surfaced from the admin dashboard (/admin). The feature itself is
 * configured by env vars (see AutoTagConfigProvider); these endpoints just run/poll the jobs.
 */
#[IsGranted('ROLE_ADMIN')]
class AutoTagConfigController extends AbstractController
{
    // Per-job message classes on the shared autotag_batch queue: the coordinator that kicks a run
    // off plus the per-item fan-out it expands into. One source of truth for status and launch guards.
    private const TAGGING_CLASSES = ['EnqueueBacklogMessage', 'GenerateSuggestionsMessage'];
    private const VECTOR_CLASSES = ['EnqueueVectorBacklogMessage', 'GenerateVectorMessage'];

    // job key (matches data-job-status-key in the template) → its message classes, so one cancel
    // route can clear any job's queue.
    private const JOB_CLASSES = [
        'tagging' => self::TAGGING_CLASSES,
        'vectors' => self::VECTOR_CLASSES,
    ];

    // A message reserved (delivered_at set) longer than this is treated as abandoned: its worker died
    // before acking, and per-item handlers run in well under a second. Otherwise one orphaned row would
    // wedge the card on "In progress" and block relaunch until the transport's redeliver_timeout (1h).
    private const int STALE_RESERVED_SECONDS = 300;

    /**
     * Kick off retroactive tagging of existing posts (the fan-out happens on the worker).
     */
    #[Route(path: '/admin/autotag/tag-backlog', name: 'app_autotag_tag_backlog', methods: ['POST'])]
    public function tagBacklog(Request $request, AutoTagConfigProvider $autoTagConfigProvider, MessageBusInterface $messageBus, TranslatorInterface $translator, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('autotag_batch', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if (!$autoTagConfigProvider->isEnabled()) {
            throw $this->createNotFoundException();
        }

        // Don't stack a second run on top of one already queued/in flight (would duplicate coordinators).
        // The Jobs panel disables the buttons too, but this is the real guard.
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

    /**
     * Live progress for every admin job, polled once by the Jobs panel (one request for all cards).
     * Each job returns a uniform, display-ready shape built by buildJobStatus().
     */
    #[Route(path: '/admin/autotag/jobs', name: 'app_autotag_jobs', methods: ['GET'])]
    public function jobs(PostRepository $postRepository, AutoTagConfigProvider $autoTagConfigProvider, Connection $connection, TranslatorInterface $translator): JsonResponse
    {
        // Shared across every job: one count instead of one per card.
        $total = $postRepository->countAll();

        // Tagging is scoped to the boards selected for at least one model
        // (APP_AUTOTAG_BOARDS_WITH_WD / APP_AUTOTAG_BOARDS_WITH_RAM).
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
            $this->pendingCounts($connection, self::TAGGING_CLASSES),
            $this->coordinatorCounts($connection, self::TAGGING_CLASSES),
            $taggingProcessed,
            $taggingTotal,
            'label.tagging_done',
            'label.tagging_todo',
        );

        // Duplicate-detection vectors: core feature, independent of auto-tagging.
        $vectors = $this->buildJobStatus(
            $translator,
            $this->pendingCounts($connection, self::VECTOR_CLASSES),
            $this->coordinatorCounts($connection, self::VECTOR_CLASSES),
            $total - $postRepository->countWithoutVector(),
            $total,
            'label.vectors_done',
            'label.vectors_todo',
        );

        return $this->json([
            'tagging' => $tagging,
            'vectors' => $vectors,
        ]);
    }

    /**
     * Uniform, display-ready status for one job. A job that isn't running always reports its coverage
     * (green once complete, amber while some posts remain) — there is no "idle" state.
     */
    private function buildJobStatus(TranslatorInterface $translator, array $counts, array $coordinatorCounts, int $processed, int $total, string $doneKey, string $todoKey): array
    {
        // The backlog coordinator gates the per-item queue. While it's in flight a worker is fanning out
        // ("starting"); once it's gone (pending == 0) the queue holds the whole run and the bar can trust
        // it. delivered > 0 means a worker has picked messages up; pending-but-undelivered is still queued.
        $fannedOut = $coordinatorCounts['pending'] === 0; // coordinator done, or never ran
        $starting = $coordinatorCounts['delivered'] > 0;  // worker actively fanning out the per-item queue
        $running = $fannedOut && $counts['delivered'] > 0; // per-item messages being processed
        $waiting = !$starting && !$running && $counts['pending'] > 0; // queued, but no worker on it yet
        $remaining = max($total - $processed, 0);

        // The bar is trustworthy only once the coordinator has fanned out — then it's driven straight off
        // the queue: a backlog run enqueues one message per post, so (all posts − messages still queued) /
        // all posts is the true progress. It works for an "All" re-run (coverage is frozen, but the queue
        // still drains) as well as a "Missing" run (starts at current coverage, climbs to 100%). No
        // run-state to persist: the queue itself is the source of truth. Otherwise it falls back to
        // coverage (and is hidden anyway).
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

    /**
     * Queue counts for a job's backlog coordinator alone (the first class in its list, e.g.
     * EnqueueBacklogMessage). While the coordinator is present the per-item queue is still
     * incomplete: delivered > 0 means a worker is fanning out ("starting"); pending-but-undelivered means
     * it's still queued with no worker ("waiting").
     */
    private function coordinatorCounts(Connection $connection, array $messageClasses): array
    {
        return $this->pendingCounts($connection, [$messageClasses[0]]);
    }

    /**
     * Kick off a bulk recompute of the perceptual duplicate-detection vector (fan-out on the worker).
     * NOT feature-gated: duplicate detection is pure PHP/GD and works with or without auto-tagging.
     */
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

    /**
     * Cancel a running job by clearing its queued messages. Only removes messages still waiting
     * (delivered_at IS NULL); a row a worker holds is left to finish to avoid racing its ack.
     * {job} is one of the JOB_CLASSES keys.
     */
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

    /**
     * Queued message counts for one job on the shared autotag_batch queue (Doctrine transport). Filters
     * by class name in the serialized envelope so a run counts against only its own card. delivered_at
     * marks a reserved (in-flight) message; anything reserved longer than STALE_RESERVED_SECONDS is
     * treated as abandoned and excluded. Returns zeros when the transport table is missing (tests).
     */
    private function pendingCounts(Connection $connection, array $messageClasses): array
    {
        try {
            // tablesExist is a safe metadata lookup: querying a missing table would abort the
            // surrounding transaction.
            if (!$connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return ['pending' => 0, 'delivered' => 0];
            }

            // "delivered" = rows reserved within the freshness window (currently processing); "pending" =
            // queued plus freshly-reserved. Stale reservations are excluded from both, so a dead worker's
            // zombie row neither shows as running nor blocks relaunch.
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

    /**
     * Delete a job's still-waiting messages (delivered_at IS NULL only, leaving in-flight rows to
     * finish). Returns rows removed; no-op when the transport table is missing (tests).
     */
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

    /**
     * Build the "body LIKE ? OR body LIKE ? ..." fragment and its bound params (with wrapping
     * wildcards) for a set of message-class substrings. Shared so the status count and the cancel
     * delete filter the shared queue identically.
     */
    private function bodyLikeFilter(array $messageClasses): array
    {
        $clause = implode(' OR ', array_fill(0, \count($messageClasses), 'body LIKE ?'));
        $params = array_map(static fn (string $class): string => '%'.$class.'%', $messageClasses);

        return [$clause, $params];
    }
}

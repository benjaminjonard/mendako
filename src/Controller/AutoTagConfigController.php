<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\EnqueueBacklogMessage;
use App\Message\EnqueueEmbeddingBacklogMessage;
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
    private const EMBEDDING_CLASSES = ['EnqueueEmbeddingBacklogMessage', 'GenerateEmbeddingMessage'];
    private const VECTOR_CLASSES = ['EnqueueVectorBacklogMessage', 'GenerateVectorMessage'];

    // job key (matches data-job-status-key in the template) → its message classes, so one cancel
    // route can clear any job's queue.
    private const JOB_CLASSES = [
        'tagging' => self::TAGGING_CLASSES,
        'embedding' => self::EMBEDDING_CLASSES,
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
    public function jobs(PostRepository $postRepository, Connection $connection, TranslatorInterface $translator): JsonResponse
    {
        // Shared across every job: one count instead of one per card.
        $total = $postRepository->countAll();

        $tagging = $this->buildJobStatus(
            $translator,
            $this->pendingCounts($connection, self::TAGGING_CLASSES),
            $total - $postRepository->countWithoutSuggestions(),
            $total,
            'label.tagging_done',
            'label.tagging_todo',
        );

        $embedding = $this->buildJobStatus(
            $translator,
            $this->pendingCounts($connection, self::EMBEDDING_CLASSES),
            $total - $postRepository->countWithoutEmbedding(),
            $total,
            'label.embedding_done',
            'label.embedding_todo',
        );

        // Duplicate-detection vectors: core feature, independent of auto-tagging.
        $vectors = $this->buildJobStatus(
            $translator,
            $this->pendingCounts($connection, self::VECTOR_CLASSES),
            $total - $postRepository->countWithoutVector(),
            $total,
            'label.vectors_done',
            'label.vectors_todo',
        );

        return $this->json([
            'tagging' => $tagging,
            'embedding' => $embedding,
            'vectors' => $vectors,
        ]);
    }

    /**
     * Uniform, display-ready status for one job. A job that isn't running always reports its coverage
     * (green once complete, amber while some posts remain) — there is no "idle" state.
     */
    private function buildJobStatus(TranslatorInterface $translator, array $counts, int $processed, int $total, string $doneKey, string $todoKey): array
    {
        // delivered > 0 means a worker has picked messages up; pending-but-none-delivered is still queued.
        $running = $counts['delivered'] > 0;
        $waiting = $counts['pending'] > 0 && !$running;
        $remaining = max($total - $processed, 0);

        return [
            'processed' => $processed,
            'total' => $total,
            'running' => $counts['pending'] > 0, // queued OR in flight — keep launch buttons disabled
            'state' => match (true) {
                $running => 'running',
                $waiting => 'waiting',
                $remaining > 0 => 'partial',
                default => 'done',
            },
            'label' => match (true) {
                $running => \sprintf('%s — %s %s', $translator->trans('label.run_active'), number_format($counts['pending']), $translator->trans('label.run_remaining')),
                $waiting => $translator->trans('label.run_waiting'),
                $remaining > 0 => \sprintf('%s %s · %s %s', number_format($processed), $translator->trans($doneKey), number_format($remaining), $translator->trans($todoKey)),
                default => \sprintf('%s %s', number_format($processed), $translator->trans($doneKey)),
            },
            'showBar' => $running || $waiting,
        ];
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
     * Kick off a bulk embedding run (fills the embedding pool for kNN / classifier). The
     * fan-out happens on the worker; posts only for now.
     */
    #[Route(path: '/admin/autotag/embed-backlog', name: 'app_autotag_embed_backlog', methods: ['POST'])]
    public function embedBacklog(Request $request, AutoTagConfigProvider $autoTagConfigProvider, MessageBusInterface $messageBus, TranslatorInterface $translator, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('autotag_embed', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if (!$autoTagConfigProvider->isEnabled()) {
            throw $this->createNotFoundException();
        }

        if ($this->pendingCounts($connection, self::EMBEDDING_CLASSES)['pending'] > 0) {
            $this->addFlash('notice', $translator->trans('message.job_already_running'));

            return $this->redirectToRoute('app_admin_jobs');
        }

        $messageBus->dispatch(
            new EnqueueEmbeddingBacklogMessage($request->request->getBoolean('all')),
            [new TransportNamesStamp('autotag_batch')],
        );
        $this->addFlash('notice', $translator->trans('message.embeddings_started'));

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

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
    // off plus the per-item fan-out it expands into. Used both to report status and to guard against
    // launching a job that already has work queued — one source of truth so the two can't drift.
    private const TAGGING_CLASSES = ['EnqueueBacklogMessage', 'GenerateSuggestionsMessage'];
    private const EMBEDDING_CLASSES = ['EnqueueEmbeddingBacklogMessage', 'GenerateEmbeddingMessage'];
    private const VECTOR_CLASSES = ['EnqueueVectorBacklogMessage', 'GenerateVectorMessage'];

    // job key (matches data-job-status-key in the template) → its message classes. Drives the cancel
    // endpoint so a single route can clear any job's queue without a per-job action.
    private const JOB_CLASSES = [
        'tagging' => self::TAGGING_CLASSES,
        'embedding' => self::EMBEDDING_CLASSES,
        'vectors' => self::VECTOR_CLASSES,
    ];

    // A reserved message (delivered_at set) whose worker died before acking it stays "reserved" until
    // the transport's redeliver_timeout (default 1h). Per-item handlers run in well under a second, so
    // a message reserved longer than this is treated as abandoned — otherwise a single orphaned row
    // wedges the card on "In progress" and blocks relaunch long after the job is actually done.
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
            $this->addFlash('notice', $translator->trans('message.automatic_tags_disabled'));

            return $this->redirectToRoute('app_admin_jobs');
        }

        // Don't stack a second run on top of one already queued/in flight — that's what produced the
        // duplicate coordinators. The Jobs panel also disables the buttons, but this is the real guard.
        if ($this->pendingCounts($connection, self::TAGGING_CLASSES)['pending'] > 0) {
            $this->addFlash('notice', $translator->trans('message.job_already_running'));

            return $this->redirectToRoute('app_admin_jobs');
        }

        $messageBus->dispatch(
            new EnqueueBacklogMessage('post', $request->request->getBoolean('all')),
            [new TransportNamesStamp('autotag_batch')],
        );
        $this->addFlash('notice', $translator->trans('message.automatic_tags_started'));

        return $this->redirectToRoute('app_admin_jobs');
    }

    /**
     * Live progress for every admin job, polled once by the Jobs panel (one request for all cards,
     * so adding jobs never multiplies the polling). Each job returns a uniform, display-ready shape:
     *   processed/total — progress-bar numbers
     *   running         — whether a run is in flight (launch buttons stay disabled while true)
     *   state           — semantic status the JS maps to a Bulma tag colour (running/waiting/partial/done)
     *   label           — fully translated status text (i18n lives here, the controller just paints it)
     *   showBar         — whether the progress bar is visible for this job
     */
    #[Route(path: '/admin/autotag/jobs', name: 'app_autotag_jobs', methods: ['GET'])]
    public function jobs(PostRepository $postRepository, Connection $connection, TranslatorInterface $translator): JsonResponse
    {
        // Shared across every job: one count instead of one per card.
        $total = $postRepository->countAll();

        // Every job reports through the same builder, so the three cards behave identically:
        // coverage count, green when complete, amber while incomplete, and a progress bar only
        // while a run is queued or in flight. Each job supplies only its own count and wording.
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
     * Uniform, display-ready status for one job — every job goes through this so the three cards
     * behave identically. There is no "idle": a job that isn't running always reports its coverage,
     * green once complete and amber while some posts remain.
     *
     * @param array{pending: int, delivered: int} $counts queued fan-out for this job
     * @param non-empty-string                    $doneKey translation key for the processed noun (e.g. "embedded")
     * @param non-empty-string                    $todoKey translation key for the remaining noun (e.g. "to embed")
     *
     * @return array{processed: int, total: int, running: bool, state: string, label: string, showBar: bool}
     */
    private function buildJobStatus(TranslatorInterface $translator, array $counts, int $processed, int $total, string $doneKey, string $todoKey): array
    {
        // delivered > 0 → a worker has picked messages up (running); pending-but-none-delivered →
        // queued and still waiting for a worker.
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
     * Kick off a bulk recompute of the perceptual duplicate-detection vector (the fan-out happens on
     * the worker). NOT feature-gated: duplicate detection works with or without auto-tagging, and the
     * recompute is pure PHP/GD (no ML service call).
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
            $this->addFlash('notice', $translator->trans('message.automatic_tags_disabled'));

            return $this->redirectToRoute('app_admin_jobs');
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
     * Cancel a running job by clearing its queued messages. Removes only messages still waiting in the
     * queue (delivered_at IS NULL) — a row a worker currently holds is left to finish, since deleting
     * it mid-process would race the worker's ack. {job} is one of the JOB_CLASSES keys.
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
     * Queued message counts for one job on the shared autotag_batch queue (Doctrine transport). Each
     * job spans two message classes — a coordinator that kicks the run off (e.g. EnqueueBacklogMessage)
     * and the per-item fan-out the coordinator expands into (e.g. GenerateSuggestionsMessage). Pass
     * both so a run counts from the moment it's queued, before a worker has expanded the coordinator.
     * The queue is shared across jobs, so filter by class name in the serialized envelope — otherwise
     * e.g. an embedding run makes the tagging card look active.
     *
     * Returns ['pending' => outstanding work, 'delivered' => currently being processed]. In the Doctrine
     * transport a worker stamps delivered_at when it reserves a message, so a fresh delivered_at means a
     * worker is actively processing; pending-but-none-delivered means the run is still waiting for a
     * worker to pick it up. Messages reserved longer than STALE_RESERVED_SECONDS are treated as abandoned
     * (dead worker) and excluded from both counts. Returns zeros when the transport table doesn't exist
     * (e.g. the in-memory transport used in tests).
     *
     * @param list<string> $messageClasses class-name substrings to match (coordinator + fan-out)
     *
     * @return array{pending: int, delivered: int}
     */
    private function pendingCounts(Connection $connection, array $messageClasses): array
    {
        try {
            // Guard the count: querying a missing table (e.g. the in-memory transport used in
            // tests) would fail and abort the surrounding transaction. tablesExist is a safe
            // metadata lookup that never poisons it.
            if (!$connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return ['pending' => 0, 'delivered' => 0];
            }

            // "delivered" = rows a worker is *currently* processing: delivered_at within the freshness
            // window. Rows reserved longer ago are abandoned (dead worker) and excluded from both counts,
            // so a zombie neither shows as running nor blocks relaunch. "pending" = still-outstanding work
            // = queued (delivered_at IS NULL) plus freshly-reserved, but never the stale ones.
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
     * Delete a job's still-waiting messages from the queue (delivered_at IS NULL only, so an in-flight
     * message a worker holds is left to finish). Returns the number of rows removed. No-op when the
     * transport table is missing (e.g. the in-memory transport used in tests).
     *
     * @param list<string> $messageClasses coordinator + fan-out class-name substrings
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
     *
     * @param list<string> $messageClasses
     *
     * @return array{0: string, 1: list<string>}
     */
    private function bodyLikeFilter(array $messageClasses): array
    {
        $clause = implode(' OR ', array_fill(0, \count($messageClasses), 'body LIKE ?'));
        $params = array_map(static fn (string $class): string => '%'.$class.'%', $messageClasses);

        return [$clause, $params];
    }
}

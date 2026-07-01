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
    /**
     * Kick off retroactive tagging of existing posts (the fan-out happens on the worker).
     */
    #[Route(path: '/admin/autotag/tag-backlog', name: 'app_autotag_tag_backlog', methods: ['POST'])]
    public function tagBacklog(Request $request, AutoTagConfigProvider $autoTagConfigProvider, MessageBusInterface $messageBus, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('autotag_batch', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if (!$autoTagConfigProvider->isEnabled()) {
            $this->addFlash('notice', $translator->trans('message.automatic_tags_disabled'));

            return $this->redirectToRoute('app_admin_index');
        }

        $messageBus->dispatch(
            new EnqueueBacklogMessage('post', $request->request->getBoolean('all')),
            [new TransportNamesStamp('autotag_batch')],
        );
        $this->addFlash('notice', $translator->trans('message.automatic_tags_started'));

        return $this->redirectToRoute('app_admin_index');
    }

    /**
     * Live progress for every admin job, polled once by the Jobs panel (one request for all cards,
     * so adding jobs never multiplies the polling). Each job returns a uniform, display-ready shape:
     *   processed/total — progress-bar numbers
     *   running         — whether a run is in flight (launch buttons stay disabled while true)
     *   state           — semantic status the JS maps to a Bulma tag colour (running/partial/done/idle)
     *   label           — fully translated status text (i18n lives here, the controller just paints it)
     *   showBar         — whether the progress bar is visible for this job
     */
    #[Route(path: '/admin/autotag/jobs', name: 'app_autotag_jobs', methods: ['GET'])]
    public function jobs(PostRepository $postRepository, Connection $connection, TranslatorInterface $translator): JsonResponse
    {
        // Shared across every job: one count instead of one per card.
        $total = $postRepository->countAll();

        // --- Retroactive tagging ------------------------------------------------------------------
        // processed = posts with at least one suggestion. pending = queued fan-out messages; > 0
        // means a run is in flight (the count visibly drains as work happens).
        $tagPending = $this->pendingBatchCount($connection);
        $tagRunning = $tagPending > 0;
        $tagging = [
            'processed' => $total - $postRepository->countWithoutSuggestions(),
            'total' => $total,
            'running' => $tagRunning,
            'state' => $tagRunning ? 'running' : 'idle',
            'label' => $tagRunning
                ? \sprintf('%s — %s %s', $translator->trans('label.run_active'), number_format($tagPending), $translator->trans('label.run_remaining'))
                : $translator->trans('label.run_idle'),
            'showBar' => true,
        ];

        // --- Embedding pool -----------------------------------------------------------------------
        $embedded = $total - $postRepository->countWithoutEmbedding();
        $embedRunning = $this->pendingEmbeddingCount($connection) > 0;
        $remaining = max($total - $embedded, 0);
        $embedding = [
            'processed' => $embedded,
            'total' => $total,
            'running' => $embedRunning,
            'state' => $embedRunning ? 'running' : ($remaining > 0 ? 'partial' : 'done'),
            'label' => match (true) {
                $embedRunning => \sprintf('%s %s/%s', $translator->trans('label.embedding_running'), number_format($embedded), number_format($total)),
                $remaining > 0 => \sprintf('%s %s · %s %s', number_format($embedded), $translator->trans('label.embedded'), number_format($remaining), $translator->trans('label.to_embed')),
                default => \sprintf('%s %s', number_format($embedded), $translator->trans('label.embedded')),
            },
            'showBar' => $embedRunning,
        ];

        // --- Duplicate-detection vectors ----------------------------------------------------------
        // Core feature, independent of auto-tagging: processed = posts carrying a perceptual pHash.
        $vectorPending = $this->pendingVectorCount($connection);
        $vectorRunning = $vectorPending > 0;
        $vectors = [
            'processed' => $total - $postRepository->countWithoutVector(),
            'total' => $total,
            'running' => $vectorRunning,
            'state' => $vectorRunning ? 'running' : 'idle',
            'label' => $vectorRunning
                ? \sprintf('%s — %s %s', $translator->trans('label.run_active'), number_format($vectorPending), $translator->trans('label.run_remaining'))
                : $translator->trans('label.run_idle'),
            'showBar' => true,
        ];

        return $this->json([
            'tagging' => $tagging,
            'embedding' => $embedding,
            'vectors' => $vectors,
        ]);
    }

    /**
     * Kick off a bulk recompute of the perceptual duplicate-detection vector (the fan-out happens on
     * the worker). NOT feature-gated: duplicate detection works with or without auto-tagging, and the
     * recompute is pure PHP/GD (no ML service call).
     */
    #[Route(path: '/admin/vectors/backlog', name: 'app_vectors_backlog', methods: ['POST'])]
    public function vectorBacklog(Request $request, MessageBusInterface $messageBus, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('autotag_vectors', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $messageBus->dispatch(
            new EnqueueVectorBacklogMessage($request->request->getBoolean('all')),
            [new TransportNamesStamp('autotag_batch')],
        );
        $this->addFlash('notice', $translator->trans('message.vectors_started'));

        return $this->redirectToRoute('app_admin_index');
    }

    /**
     * Kick off a bulk embedding run (fills the embedding pool for kNN / classifier). The
     * fan-out happens on the worker; posts only for now.
     */
    #[Route(path: '/admin/autotag/embed-backlog', name: 'app_autotag_embed_backlog', methods: ['POST'])]
    public function embedBacklog(Request $request, AutoTagConfigProvider $autoTagConfigProvider, MessageBusInterface $messageBus, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('autotag_embed', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if (!$autoTagConfigProvider->isEnabled()) {
            $this->addFlash('notice', $translator->trans('message.automatic_tags_disabled'));

            return $this->redirectToRoute('app_admin_index');
        }

        $messageBus->dispatch(
            new EnqueueEmbeddingBacklogMessage($request->request->getBoolean('all')),
            [new TransportNamesStamp('autotag_batch')],
        );
        $this->addFlash('notice', $translator->trans('message.embeddings_started'));

        return $this->redirectToRoute('app_admin_index');
    }

    /**
     * Number of queued retroactive-tagging messages (Doctrine transport). Returns 0 when the
     * transport table doesn't exist (e.g. the in-memory transport used in tests).
     */
    private function pendingBatchCount(Connection $connection): int
    {
        try {
            // Guard the count: querying a missing table (e.g. the in-memory transport used in
            // tests) would fail and abort the surrounding transaction. tablesExist is a safe
            // metadata lookup that never poisons it.
            if (!$connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return 0;
            }

            // autotag_batch is shared with the embedding-pool job, so count only the tagging
            // fan-out messages — otherwise an embedding run makes the tagging card look active.
            return (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND body LIKE '%GenerateSuggestionsMessage%'",
                ['autotag_batch'],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Queued embedding messages specifically (the autotag_batch queue is shared with tagging, so
     * filter by the message class in the serialized envelope). > 0 means an embed run is in flight.
     */
    private function pendingEmbeddingCount(Connection $connection): int
    {
        try {
            if (!$connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return 0;
            }

            return (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND body LIKE '%GenerateEmbeddingMessage%'",
                ['autotag_batch'],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Queued duplicate-detection vector recompute messages (the autotag_batch queue is shared, so
     * filter by the message class in the serialized envelope). > 0 means a recompute is in flight.
     */
    private function pendingVectorCount(Connection $connection): int
    {
        try {
            if (!$connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return 0;
            }

            return (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND body LIKE '%GenerateVectorMessage%'",
                ['autotag_batch'],
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}

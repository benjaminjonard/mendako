<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\EnqueueBacklogMessage;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
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

        // The worker encodes the zero-shot vocabulary first, then fans out (see
        // EnqueueBacklogHandler), so images are only analyzed once the tags are cached.
        $messageBus->dispatch(
            new EnqueueBacklogMessage('post', $request->request->getBoolean('all')),
            [new TransportNamesStamp('autotag_batch')],
        );
        $this->addFlash('notice', $translator->trans('message.automatic_tags_started'));

        return $this->redirectToRoute('app_admin_index');
    }

    /**
     * Live backlog progress, polled by the status panel. processed = posts with at
     * least one suggestion; running = whether a retroactive run is still in flight.
     */
    #[Route(path: '/admin/autotag/batch-status', name: 'app_autotag_batch_status', methods: ['GET'])]
    public function batchStatus(PostRepository $postRepository, AutoTagInferenceClient $autoTagInferenceClient, Connection $connection): JsonResponse
    {
        $total = $postRepository->countAll();
        // Queued items still to process; > 0 means a run is in flight. The UI shows this
        // count (it visibly drains) and disables the launch buttons so a second run can't
        // be started on top of it.
        $pending = $this->pendingBatchCount($connection);

        // One-off vocabulary encoding progress, surfaced so the UI can show "Encoding tags X/Y".
        $vocabulary = $autoTagInferenceClient->vocabularyStatus();

        return $this->json([
            'processed' => $total - $postRepository->countWithoutSuggestions(),
            'total' => $total,
            'pending' => $pending,
            'running' => $pending > 0,
            'vocabularyWarming' => (bool) ($vocabulary['running'] ?? false),
            'vocabularyDone' => (int) ($vocabulary['done'] ?? 0),
            'vocabularyTotal' => (int) ($vocabulary['total'] ?? 0),
        ]);
    }

    /**
     * Tag-cache job status: how many tag names are encoded vs still to encode, plus the live
     * encoding progress. Polled by the cache-status panel.
     */
    #[Route(path: '/admin/autotag/cache-status', name: 'app_autotag_cache_status', methods: ['GET'])]
    public function cacheStatus(AutoTagConfigProvider $autoTagConfigProvider, AutoTagInferenceClient $autoTagInferenceClient, TagRepository $tagRepository): JsonResponse
    {
        $clipModelId = $autoTagConfigProvider->getActiveModel('clip');
        $coverage = $clipModelId !== null
            ? $autoTagInferenceClient->vocabularyMissing($clipModelId, $tagRepository->findAllNames())
            : [];
        $warm = $autoTagInferenceClient->vocabularyStatus();

        return $this->json([
            'cached' => (int) ($coverage['cached'] ?? 0),
            'missing' => (int) ($coverage['missing'] ?? 0),
            'total' => (int) ($coverage['total'] ?? 0),
            'running' => (bool) ($warm['running'] ?? false),
            'done' => (int) ($warm['done'] ?? 0),
            'encodeTotal' => (int) ($warm['total'] ?? 0),
        ]);
    }

    /**
     * Encode the tag vocabulary into the service's persistent cache (incremental: only the
     * not-yet-cached tags are encoded, in the background, then textual.onnx is unloaded).
     */
    #[Route(path: '/admin/autotag/cache-encode', name: 'app_autotag_cache_encode', methods: ['POST'])]
    public function cacheEncode(Request $request, AutoTagConfigProvider $autoTagConfigProvider, AutoTagInferenceClient $autoTagInferenceClient, TagRepository $tagRepository, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('autotag_cache', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if (!$autoTagConfigProvider->isEnabled()) {
            $this->addFlash('notice', $translator->trans('message.automatic_tags_disabled'));

            return $this->redirectToRoute('app_admin_index');
        }

        // all=true re-encodes the whole vocabulary (wipes the cache first); otherwise only the
        // not-yet-cached tags are encoded.
        $clipModelId = $autoTagConfigProvider->getActiveModel('clip');
        if ($clipModelId !== null) {
            $autoTagInferenceClient->warmVocabulary($clipModelId, $tagRepository->findAllNames(), $request->request->getBoolean('all'));
        }
        $this->addFlash('notice', $translator->trans('message.vocab_encoding_started'));

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

            return (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
                ['autotag_batch'],
            );
        } catch (\Throwable) {
            return 0;
        }
    }
}

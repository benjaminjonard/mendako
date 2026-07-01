<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Post;
use App\Form\Type\PostType;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use App\Repository\TagSuggestionRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\TaggingDispatcher;
use App\Service\PostVectorService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class PostController extends AbstractController
{
    /**
     * Suggestions scoring at or above this bar are confident enough to pre-fill the
     * tag field; lower-confidence ones stay as clickable pending chips. Matches the
     * WD character threshold; a single bar for now (per-category/configurable later).
     */
    private const float HIGH_CONFIDENCE_THRESHOLD = 0.85;

    #[Route(path: '/upload', name: 'app_post_upload', methods: ['GET', 'POST'])]
    #[Route(path: '/boards/{slug}/add', name: 'app_post_add', methods: ['GET', 'POST'])]
    public function add(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        TagRepository $tagRepository,
        PostVectorService $postVectorService,
        TaggingDispatcher $taggingDispatcher,
        #[MapEntity(mapping: ['slug' => 'slug'])] ?Board $board
    ): Response {
        $post = new Post();
        $post->setBoard($board);

        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $post
                ->setUploadedBy($this->getUser())
                ->setVector($postVectorService->generateVector($post->getFile()))
            ;

            if ($form->get('setAsBoardThumbnail')->getData() === true) {
                $post->getBoard()->setThumbnail($post);
            }

            $managerRegistry->getManager()->persist($post);
            $managerRegistry->getManager()->flush();

            $taggingDispatcher->dispatch($post);

            $this->addFlash('notice', $translator->trans('message.post_added'));

            return $this->redirectToRoute('app_post_show', ['slug' => $post->getBoard()->getSlug(), 'id' => $post->getId()]);
        }

        return $this->render($board ? 'App/Post/add.html.twig' : 'App/Post/upload.html.twig', [
            'board' => $board,
            'post' => $post,
            'form' => $form,
            'suggestedTags' => $tagRepository->findBy(['suggested' => true]),
            // No post id before upload — automatic tagging suggestions surface on the edit form.
            'autoTagSuggestionsUrl' => null,
        ]);
    }

    #[Route(path: '/check-similar', name: 'app_post_check_similar', methods: ['POST'])]
    public function checkSimilar(Request $request, PostVectorService $postVectorService, PostRepository $postRepository): JsonResponse
    {
        $post = new Post();
        $post->setFile($request->files->get('file'));

        $vector = $postVectorService->generateVector($post->getFile());
        $similarPosts = [];

        foreach ($postRepository->findSimilarByVector($vector) as $similarPost) {
            $similarPosts[] = $this->renderView('App/Post/_similar.html.twig', [
                'post' => $similarPost
            ]);
        }

        return $this->json($similarPosts);
    }

    #[Route(path: '/boards/{slug}/{id}', name: 'app_post_show', methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['slug' => 'slug'])] Board $board,
        Post $post,
        TagRepository $tagRepository
    ): Response {
        return $this->render('App/Post/show.html.twig', [
            'board' => $board,
            'post' => $post,
            'tags' => $tagRepository->findForPosts($board, [$post])
        ]);
    }

    #[Route(path: '/boards/{slug}/{id}/edit', name: 'app_post_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        TranslatorInterface $translator,
        TagRepository $tagRepository,
        ManagerRegistry $managerRegistry,
        PostVectorService $postVectorService,
        AutoTagConfigProvider $autoTagConfigProvider,
        #[MapEntity(mapping: ['slug' => 'slug'])] Board $board,
        Post $post
    ): Response {
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $post
                ->setUploadedBy($this->getUser())
                ->setVector($postVectorService->generateVector($post->getFile()))
            ;

            if ($form->get('setAsBoardThumbnail')->getData() === true) {
                $board->setThumbnail($post);
            }

            $managerRegistry->getManager()->persist($post);
            $managerRegistry->getManager()->flush();

            $this->addFlash('notice', $translator->trans('message.post_edited'));

            return $this->redirectToRoute('app_post_show', ['slug' => $board->getSlug(), 'id' => $post->getId()]);
        }

        return $this->render('App/Post/edit.html.twig', [
            'board' => $board,
            'post' => $post,
            'form' => $form,
            'suggestedTags' => $tagRepository->findBy(['suggested' => true]),
            // Only surface the automatic tagging suggestions endpoint when the feature is on; null keeps
            // the form byte-identical to the non-automatic tagging experience.
            'autoTagSuggestionsUrl' => $autoTagConfigProvider->isEnabled()
                ? $this->generateUrl('app_post_autotag_suggestions', ['slug' => $board->getSlug(), 'id' => $post->getId()])
                : null,
        ]);
    }

    #[Route(path: '/boards/{slug}/{id}/autotag-suggestions', name: 'app_post_autotag_suggestions', methods: ['GET'])]
    public function autoTagSuggestions(
        AutoTagConfigProvider $autoTagConfigProvider,
        TagSuggestionRepository $tagSuggestionRepository,
        #[MapEntity(mapping: ['slug' => 'slug'])] Board $board,
        Post $post
    ): JsonResponse {
        if (!$autoTagConfigProvider->isEnabled()) {
            return $this->json(['enabled' => false]);
        }

        $pending = array_filter(
            $tagSuggestionRepository->findForTarget('post', $post->getId()),
            static fn ($suggestion): bool => $suggestion->getStatus() === \App\Entity\TagSuggestion::STATUS_PENDING
        );

        $entry = static fn ($suggestion): array => [
            'name' => $suggestion->getTagName(),
            'category' => $suggestion->getCategory()?->value ?? 'general',
            'score' => $suggestion->getScore(),
            'source' => $suggestion->getSource(),
        ];

        // The same tag can have rows from several sources (wd / clip / knn). Dedup by name
        // so a tag never appears twice. Pass 1: confident base-model (wd) tags pre-fill the
        // field. Pass 2: everything else becomes a click-to-add chip — learned (knn/clip)
        // guesses are never auto-applied. A name already pre-filled is not repeated as a chip.
        $highConfidence = [];
        $lowConfidence = [];
        $seen = [];
        foreach ($pending as $suggestion) {
            $name = $suggestion->getTagName();
            if ($suggestion->getSource() === \App\Entity\TagSuggestion::SOURCE_WD
                && $suggestion->getScore() >= self::HIGH_CONFIDENCE_THRESHOLD
                && !isset($seen[$name])) {
                $highConfidence[] = $entry($suggestion);
                $seen[$name] = true;
            }
        }
        foreach ($pending as $suggestion) {
            $name = $suggestion->getTagName();
            if (!isset($seen[$name])) {
                $lowConfidence[] = $entry($suggestion);
                $seen[$name] = true;
            }
        }

        return $this->json([
            'enabled' => true,
            // No server-side "analysis complete" marker yet: infer "still analyzing"
            // from the absence of any suggestion (the client polls with a bounded cap).
            'status' => $pending === [] ? 'analyzing' : 'ready',
            'highConfidence' => $highConfidence,
            'pending' => $lowConfidence,
        ]);
    }

    #[Route(path: '/boards/{slug}/{id}/delete', name: 'app_post_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        #[MapEntity(mapping: ['slug' => 'slug'])] Board $board,
        Post $post
    ): Response {
        $form = $this->createDeleteForm('app_post_delete', $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $managerRegistry->getManager()->remove($post);
            $managerRegistry->getManager()->flush();
            $this->addFlash('notice', $translator->trans('message.post_deleted'));
        }

        return $this->redirectToRoute('app_board_show', ['slug' => $board->getSlug()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Post;
use App\Form\DataTransformer\StringToTagTransformer;
use App\Form\Type\TagValidationType;
use App\Repository\PostRepository;
use App\Repository\TagSuggestionRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\SuggestionSplitter;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
class TagValidationController extends AbstractController
{
    public function __construct(
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
        private readonly SuggestionSplitter $suggestionSplitter,
    ) {
    }

    #[Route(path: '/tag-validation', name: 'app_tag_validation', methods: ['GET'])]
    public function index(
        PostRepository $postRepository,
        TagSuggestionRepository $tagSuggestionRepository,
        StringToTagTransformer $stringToTagTransformer,
    ): Response {
        $this->assertAutoTagEnabled();

        $post = $postRepository->findLatestWithPendingSuggestions();
        if ($post === null) {
            return $this->render('App/TagValidation/index.html.twig', ['post' => null]);
        }

        [$highConfidenceNames, $chips] = $this->splitSuggestions(
            $tagSuggestionRepository->findForTarget('post', $post->getId())
        );

        foreach ($stringToTagTransformer->reverseTransform(implode(' ', $highConfidenceNames)) as $tag) {
            $post->addTag($tag);
        }

        $form = $this->createForm(TagValidationType::class, $post, [
            'action' => $this->generateUrl('app_tag_validation_submit', ['id' => $post->getId()]),
        ]);

        return $this->render('App/TagValidation/index.html.twig', [
            'post' => $post,
            'form' => $form,
            'chips' => $chips,
        ]);
    }

    #[Route(path: '/tag-validation/{id}', name: 'app_tag_validation_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        TagSuggestionRepository $tagSuggestionRepository,
        Post $post,
    ): Response {
        $this->assertAutoTagEnabled();

        $form = $this->createForm(TagValidationType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager = $managerRegistry->getManager();
            $manager->persist($post);
            $manager->flush();

            $acceptedNames = array_map(
                static fn ($tag): string => $tag->getName(),
                $post->getTags()->toArray()
            );
            $tagSuggestionRepository->resolvePendingForTarget('post', $post->getId(), $acceptedNames);

            $this->addFlash('notice', $translator->trans('message.tags_validated'));
        }

        return $this->redirectToRoute('app_tag_validation');
    }

    #[Route(path: '/tag-validation/{id}/delete', name: 'app_tag_validation_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        Post $post,
    ): Response {
        $this->assertAutoTagEnabled();

        $form = $this->createDeleteForm('app_tag_validation_delete', $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manager = $managerRegistry->getManager();
            $manager->remove($post);
            $manager->flush();

            $this->addFlash('notice', $translator->trans('message.post_deleted'));
        }

        return $this->redirectToRoute('app_tag_validation');
    }

    private function assertAutoTagEnabled(): void
    {
        if (!$this->autoTagConfigProvider->isEnabled()) {
            throw $this->createNotFoundException();
        }
    }

    private function splitSuggestions(array $suggestions): array
    {
        [$confident, $chips] = $this->suggestionSplitter->split($suggestions);

        return [array_column($confident, 'name'), $chips];
    }
}

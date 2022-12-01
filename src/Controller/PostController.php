<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Post;
use App\Form\Type\PostType;
use App\Repository\TagRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class PostController extends AbstractController
{
    #[Route(path: '/upload', name: 'app_post_upload', methods: ['GET', 'POST'])]
    #[Route(path: '/boards/{slug}/add', name: 'app_post_add', methods: ['GET', 'POST'])]
    public function add(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        TagRepository $tagRepository,
        ?Board $board
    ): Response {
        $post = new Post();
        $post->setBoard($board);

        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $post->setUploadedBy($this->getUser());
            if ($form->get('setAsBoardThumbnail')->getData() === true) {
                $post->getBoard()->setThumbnail($post);
            }

            $managerRegistry->getManager()->persist($post);
            $managerRegistry->getManager()->flush();

            $this->addFlash('notice', $translator->trans('message.post_added'));

            return $this->redirectToRoute('app_post_show', ['slug' => $post->getBoard()->getSlug(), 'id' => $post->getId()]);
        }

        return $this->render($board ? 'App/Post/add.html.twig' : 'App/Post/upload.html.twig', [
            'board' => $board,
            'post' => $post,
            'form' => $form,
            'suggestedTags' => $tagRepository->findBy(['suggested' => true])
        ]);
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
        #[MapEntity(mapping: ['slug' => 'slug'])] Board $board,
        Post $post
    ): Response {
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
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
            'suggestedTags' => $tagRepository->findBy(['suggested' => true])
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

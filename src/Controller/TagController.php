<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tag;
use App\Form\Type\TagType;
use App\Repository\TagRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class TagController extends AbstractController
{
    #[Route(path: '/tags', name: 'app_tag_index', methods: ['GET'])]
    public function index(TagRepository $tagRepository): Response
    {
        $tags = $tagRepository->findWithCounters();

        return $this->render('App/Tag/index.html.twig', [
            'tags' => $tags,
        ]);
    }

    #[Route(path: '/tags/autocomplete', name: 'app_tag_autocomplete', methods: ['GET'])]
    public function add(
        Request $request,
        TagRepository $tagRepository
    ): Response {
        $query = $request->query->get('query', null);

        $tags = array_map(function (Tag $tag) {
            return [
                'name' => $tag->getName(),
                'category' => $tag->getCategory()->value
            ];
        }, $tagRepository->findLike($query));

        return $this->json($tags);
    }

    #[Route(path: '/tags/{id}/edit', name: 'app_tag_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        Tag $tag,
    ): Response {
        $form = $this->createForm(TagType::class, $tag);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $managerRegistry->getManager()->persist($tag);
            $managerRegistry->getManager()->flush();

            $this->addFlash('notice', $translator->trans('message.tag_edited', ['tag' => '&nbsp;<strong>'.$tag->getName().'</strong>&nbsp;']));

            return $this->redirectToRoute('app_tag_index');
        }

        return $this->render('App/Tag/edit.html.twig', [
            'tag' => $tag,
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/tags/{id}/delete', name: 'app_tag_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        Tag $tag,
    ): Response {
        $form = $this->createDeleteForm('app_tag_delete', $tag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $managerRegistry->getManager()->remove($tag);
            $managerRegistry->getManager()->flush();
            $this->addFlash('notice', $translator->trans('message.tag_deleted', ['tag' => '&nbsp;<strong>'.$tag->getName().'</strong>&nbsp;']));
        }

        return $this->redirectToRoute('app_tag_index');
    }
}

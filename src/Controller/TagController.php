<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tag;
use App\Form\Type\ImageType;
use App\Form\Type\TagType;
use App\Repository\BoardRepository;
use App\Repository\TagRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        $results = [];
        $tags = $tagRepository->findLike($query);
        foreach ($tags as $tag) {
            $results['results'][] = [
                'value' => $tag->getName(),
                'text' => $tag->getName(),
            ];
        }

        return $this->json($results);
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

            return $this->redirectToRoute('app_tag_edit', ['id' => $tag->getId()]);
        }

        return $this->render('App/Tag/edit.html.twig', [
            'tag' => $tag,
            'form' => $form->createView(),
        ]);
    }
}

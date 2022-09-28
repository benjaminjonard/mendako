<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Image;
use App\Form\Type\ImageType;
use App\Repository\TagRepository;
use Doctrine\Persistence\ManagerRegistry;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ImageController extends AbstractController
{
    #[Route(path: '/boards/{slug}/add', name: 'app_image_add', methods: ['GET', 'POST'])]
    public function add(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        Board $board
    ): Response {
        $image = new Image();
        $image->setBoard($board);

        $form = $this->createForm(ImageType::class, $image);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $image->setUploadedBy($this->getUser());
            if ($form->get('setAsBoardThumbnail')->getData() === true) {
               $board->setThumbnail($image);
            }

            $managerRegistry->getManager()->persist($image);
            $managerRegistry->getManager()->flush();

            $this->addFlash('notice', $translator->trans('message.image_added'));

            return $this->redirectToRoute('app_image_show', ['slug' => $board->getSlug(), 'id' => $image->getId()]);
        }

        return $this->render('App/Image/add.html.twig', [
            'board' => $board,
            'image' => $image,
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/boards/{slug}/{id}', name: 'app_image_show', methods: ['GET'])]
    #[ParamConverter('board', options: ['mapping' => ['slug' => 'slug']])]
    public function show(Board $board, Image $image, TagRepository $tagRepository): Response {
        return $this->render('App/Image/show.html.twig', [
            'board' => $board,
            'image' => $image,
            'tags' => $tagRepository->findForImage($image)
        ]);
    }

    #[Route(path: '/boards/{slug}/{id}/edit', name: 'app_image_edit', methods: ['GET', 'POST'])]
    #[ParamConverter('board', options: ['mapping' => ['slug' => 'slug']])]
    public function edit(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        Board $board,
        Image $image
    ): Response {
        $form = $this->createForm(ImageType::class, $image);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('setAsBoardThumbnail')->getData() === true) {
                $board->setThumbnail($image);
            }
            $managerRegistry->getManager()->persist($image);
            $managerRegistry->getManager()->flush();

            $this->addFlash('notice', $translator->trans('message.image_edited'));

            return $this->redirectToRoute('app_image_show', ['slug' => $board->getSlug(), 'id' => $image->getId()]);
        }

        return $this->render('App/Image/edit.html.twig', [
            'board' => $board,
            'image' => $image,
            'form' => $form->createView(),
        ]);
    }
}

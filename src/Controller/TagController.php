<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Image;
use App\Form\Type\ImageType;
use App\Repository\TagRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class TagController extends AbstractController
{
    #[Route(path: '/tags/autocomplete', name: 'app_tag_autocomplete', methods: ['GET'])]
    public function add(
        Request $request,
        TagRepository $tagRepository
    ): Response {
        $query = $request->query->get('query', '');

        $results = [];
        $tags = $tagRepository->findAll();
        foreach ($tags as $tag) {
            $results['results'][] = [
                'value' => $tag->getId(),
                'text' => $tag->getName(),
            ];
        }

        return $this->json($results);
    }
}

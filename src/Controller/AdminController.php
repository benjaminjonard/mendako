<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Service\DiskUsageCalculator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route(path: '/admin', name: 'app_admin_index', methods: ['GET', 'POST'])]
    public function index(
        DiskUsageCalculator $diskUsageCalculator,
        BoardRepository $boardRepository,
        PostRepository $postRepository,
        string $release,
        string $thumbnailsFormat,
        string $publicPath,
        string $postPerPage,
        string $infiniteScrollPostPerPage
    ): Response
    {
        return $this->render('App/Admin/index.html.twig', [
            'release' => $release,
            'thumbnailsFormat' => $thumbnailsFormat,
            'postPerPage' => $postPerPage,
            'infiniteScrollPostPerPage' => $infiniteScrollPostPerPage,
            'diskUsage' => $diskUsageCalculator->getFolderSize($publicPath.'/uploads') + $diskUsageCalculator->getFolderSize($publicPath.'/thumbnails'),
            'posts' => $postRepository->count([]),
            'boards' => $boardRepository->count([])
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Service\DiskUsageCalculator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%release%')] string $release,
        #[Autowire('%kernel.project_dir%/public/uploads')] string $uploadsPath,
        #[Autowire('%env(APP_THUMBNAILS_FORMAT)%')] string $thumbnailsFormat,
        #[Autowire('%env(APP_POST_PER_PAGE)%')] int $postPerPage,
        #[Autowire('%env(APP_INFINITE_SCROLL_POST_PER_PAGE)%')] int $infiniteScrollPostPerPage
    ): Response
    {
        return $this->render('App/Admin/index.html.twig', [
            'release' => $release,
            'thumbnailsFormat' => $thumbnailsFormat,
            'postPerPage' => $postPerPage,
            'infiniteScrollPostPerPage' => $infiniteScrollPostPerPage,
            'diskUsage' => $diskUsageCalculator->getFolderSize($uploadsPath) + $diskUsageCalculator->getFolderSize($uploadsPath),
            'posts' => $postRepository->count([]),
            'boards' => $boardRepository->count([])
        ]);
    }
}

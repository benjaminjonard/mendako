<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DiskUsageCalculator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route(path: '/admin', name: 'app_admin_index', methods: ['GET', 'POST'])]
    public function index(string $release, string $thumbnailsFormat, string $publicPath, DiskUsageCalculator $diskUsageCalculator): Response
    {
        return $this->render('App/Admin/index.html.twig', [
            'release' => $release,
            'thumbnailsFormat' => $thumbnailsFormat,
            'diskUsage' => $diskUsageCalculator->getFolderSize($publicPath.'/uploads') + $diskUsageCalculator->getFolderSize($publicPath.'/thumbnails')
        ]);
    }
}

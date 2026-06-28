<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Post;
use App\Entity\StagedUpload;
use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\StagedUploadRepository;
use App\Service\PostVectorService;
use App\Service\RandomStringGenerator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class StagedUploadController extends AbstractController
{
    public function __construct(
        private readonly RandomStringGenerator $randomStringGenerator,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath,
    ) {
    }

    #[Route(path: '/staging', name: 'app_staged_index', methods: ['GET'])]
    public function index(
        StagedUploadRepository $stagedUploadRepository,
        BoardRepository $boardRepository,
    ): Response {
        return $this->render('App/StagedUpload/index.html.twig', [
            'stagedUploads' => $stagedUploadRepository->findAllForUser($this->getUser()),
            'boards' => $boardRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route(path: '/staging/{id}/similar', name: 'app_staged_similar', methods: ['GET'])]
    public function similar(
        string $id,
        StagedUploadRepository $stagedUploadRepository,
        PostRepository $postRepository,
    ): JsonResponse {
        $stagedUpload = $stagedUploadRepository->findOneBy(['id' => $id, 'uploadedBy' => $this->getUser()]);
        if ($stagedUpload === null || $stagedUpload->getVector() === null) {
            return $this->json(['similar' => []]);
        }

        $similar = [];
        foreach ($postRepository->findSimilarByVector($stagedUpload->getVector()) as $post) {
            $similar[] = $this->renderView('App/Post/_similar.html.twig', ['post' => $post]);
        }

        return $this->json(['similar' => $similar]);
    }

    #[Route(path: '/staging/add', name: 'app_staged_add', methods: ['POST'])]
    public function add(
        Request $request,
        ManagerRegistry $managerRegistry,
        PostVectorService $postVectorService,
        PostRepository $postRepository,
        ValidatorInterface $validator,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('staged_action', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $stagedUpload = new StagedUpload();
        $stagedUpload->setFile($file);

        // Run the entity's #[Assert\File] mimetype/size constraints (no Form here).
        $violations = $validator->validate($stagedUpload);
        if (count($violations) > 0) {
            return $this->json(['error' => (string) $violations[0]->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $vector = $postVectorService->generateVector($file);
        $stagedUpload
            ->setUploadedBy($user = $this->getUser())
            ->setVector($vector)
            ->setIsDuplicate($vector !== null && $postRepository->findSimilarByVector($vector) !== [])
        ;

        $managerRegistry->getManager()->persist($stagedUpload);
        $managerRegistry->getManager()->flush();

        return $this->json([
            'id' => $stagedUpload->getId(),
            'card' => $this->renderView('App/StagedUpload/_card.html.twig', ['stagedUpload' => $stagedUpload]),
        ]);
    }

    #[Route(path: '/staging/assign', name: 'app_staged_assign', methods: ['POST'])]
    public function assign(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        StagedUploadRepository $stagedUploadRepository,
        BoardRepository $boardRepository,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('staged_action', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $board = $boardRepository->find((string) $request->request->get('board'));
        if ($board === null) {
            return $this->json(['error' => 'Board not found'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        $manager = $managerRegistry->getManager();
        $removedIds = [];
        $failedIds = [];

        $relativeDir = 'uploads/boards/' . $board->getId();
        $absoluteDir = $this->publicPath . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, recursive: true) && !is_dir($absoluteDir)) {
            return $this->json(['error' => 'Unable to create destination directory'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        foreach ((array) $request->request->all('ids') as $id) {
            // Ownership scoping: only the uploader can assign their own staged files.
            $stagedUpload = $stagedUploadRepository->findOneBy(['id' => (string) $id, 'uploadedBy' => $user]);
            if ($stagedUpload === null || $stagedUpload->getPath() === null) {
                $failedIds[] = (string) $id;
                continue;
            }

            // Fresh random name avoids collisions with existing board files / the unique path constraint.
            $extension = pathinfo((string) $stagedUpload->getPath(), PATHINFO_EXTENSION);
            $filename = $this->randomStringGenerator->generate(20) . ($extension !== '' ? '.' . $extension : '');
            $newRelativePath = $relativeDir . '/' . $filename;

            if (!@rename($this->publicPath . '/' . $stagedUpload->getPath(), $this->publicPath . '/' . $newRelativePath)) {
                // Move failed: leave the staged upload untouched so nothing is lost.
                $failedIds[] = (string) $id;
                continue;
            }

            $post = new Post();
            $post
                ->setBoard($board)
                ->setUploadedBy($stagedUpload->getUploadedBy() ?? $user)
                ->setMimetype($stagedUpload->getMimetype())
                ->setSize($stagedUpload->getSize())
                ->setWidth($stagedUpload->getWidth())
                ->setHeight($stagedUpload->getHeight())
                ->setDuration($stagedUpload->getDuration())
                ->setVector($stagedUpload->getVector())
                ->setPath($newRelativePath)
            ;
            $post->setHasSound($stagedUpload->hasSound()); // Post::setHasSound() returns void (not fluent)
            $manager->persist($post);

            // Null the staged path BEFORE removal so postRemove/removeOldFile does NOT
            // unlink the file we just moved into the board directory.
            $stagedUpload->setPath(null);
            $manager->remove($stagedUpload);

            $removedIds[] = (string) $id;
        }

        $manager->flush();

        if ($removedIds !== []) {
            $this->addFlash('notice', $translator->trans('message.staged_assigned'));
        }

        return $this->json(['removedIds' => $removedIds, 'failedIds' => $failedIds]);
    }

    #[Route(path: '/staging/delete', name: 'app_staged_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        StagedUploadRepository $stagedUploadRepository,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('staged_action', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        $manager = $managerRegistry->getManager();
        $removedIds = [];

        foreach ((array) $request->request->all('ids') as $id) {
            // Ownership scoping: only the uploader can delete their own staged files.
            $stagedUpload = $stagedUploadRepository->findOneBy(['id' => (string) $id, 'uploadedBy' => $user]);
            if ($stagedUpload === null) {
                continue;
            }

            $manager->remove($stagedUpload);
            $removedIds[] = (string) $id;
        }

        $manager->flush();

        if ($removedIds !== []) {
            $this->addFlash('notice', $translator->trans('message.staged_deleted'));
        }

        return $this->json(['removedIds' => $removedIds]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Post;
use App\Entity\BulkUpload;
use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\BulkUploadRepository;
use App\Service\AutoTag\TaggingDispatcher;
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

class BulkUploadController extends AbstractController
{
    public function __construct(
        private readonly RandomStringGenerator $randomStringGenerator,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath,
    ) {
    }

    #[Route(path: '/bulk-upload', name: 'app_bulk_upload_index', methods: ['GET'])]
    public function index(
        BulkUploadRepository $bulkUploadRepository,
        BoardRepository $boardRepository,
    ): Response {
        return $this->render('App/BulkUpload/index.html.twig', [
            'bulkUploads' => $bulkUploadRepository->findAllForUser($this->getUser()),
            'boards' => $boardRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route(path: '/bulk-upload/{id}/similar', name: 'app_bulk_upload_similar', methods: ['GET'])]
    public function similar(
        string $id,
        BulkUploadRepository $bulkUploadRepository,
        PostRepository $postRepository,
    ): JsonResponse {
        $bulkUpload = $bulkUploadRepository->findOneBy(['id' => $id, 'uploadedBy' => $this->getUser()]);
        if ($bulkUpload === null || $bulkUpload->getVector() === null) {
            return $this->json(['similar' => []]);
        }

        $similar = [];
        foreach ($postRepository->findSimilarByVector($bulkUpload->getVector()) as $post) {
            $similar[] = $this->renderView('App/Post/_similar.html.twig', ['post' => $post]);
        }

        return $this->json(['similar' => $similar]);
    }

    #[Route(path: '/bulk-upload/add', name: 'app_bulk_upload_add', methods: ['POST'])]
    public function add(
        Request $request,
        ManagerRegistry $managerRegistry,
        PostVectorService $postVectorService,
        PostRepository $postRepository,
        ValidatorInterface $validator,
        TaggingDispatcher $taggingDispatcher,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('bulk_upload_action', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $bulkUpload = new BulkUpload();
        $bulkUpload->setFile($file);

        // Run the entity's #[Assert\File] mimetype/size constraints (no Form here).
        $violations = $validator->validate($bulkUpload);
        if (count($violations) > 0) {
            return $this->json(['error' => (string) $violations[0]->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $vector = $postVectorService->generateVector($file);
        $bulkUpload
            ->setUploadedBy($user = $this->getUser())
            ->setVector($vector)
            ->setIsDuplicate($vector !== null && $postRepository->findSimilarByVector($vector) !== [])
        ;

        $managerRegistry->getManager()->persist($bulkUpload);
        $managerRegistry->getManager()->flush();

        $taggingDispatcher->dispatch($bulkUpload);

        return $this->json([
            'id' => $bulkUpload->getId(),
            'card' => $this->renderView('App/BulkUpload/_card.html.twig', ['bulkUpload' => $bulkUpload]),
        ]);
    }

    #[Route(path: '/bulk-upload/assign', name: 'app_bulk_upload_assign', methods: ['POST'])]
    public function assign(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        BulkUploadRepository $bulkUploadRepository,
        BoardRepository $boardRepository,
        TaggingDispatcher $taggingDispatcher,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('bulk_upload_action', (string) $request->request->get('_token'))) {
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
        $createdPosts = [];

        $relativeDir = 'uploads/boards/' . $board->getId();
        $absoluteDir = $this->publicPath . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, recursive: true) && !is_dir($absoluteDir)) {
            return $this->json(['error' => 'Unable to create destination directory'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        foreach ((array) $request->request->all('ids') as $id) {
            // Ownership scoping: only the uploader can assign their own bulk upload files.
            $bulkUpload = $bulkUploadRepository->findOneBy(['id' => (string) $id, 'uploadedBy' => $user]);
            if ($bulkUpload === null || $bulkUpload->getPath() === null) {
                $failedIds[] = (string) $id;
                continue;
            }

            // Fresh random name avoids collisions with existing board files / the unique path constraint.
            $extension = pathinfo((string) $bulkUpload->getPath(), PATHINFO_EXTENSION);
            $filename = $this->randomStringGenerator->generate(20) . ($extension !== '' ? '.' . $extension : '');
            $newRelativePath = $relativeDir . '/' . $filename;

            if (!@rename($this->publicPath . '/' . $bulkUpload->getPath(), $this->publicPath . '/' . $newRelativePath)) {
                // Move failed: leave the bulk upload untouched so nothing is lost.
                $failedIds[] = (string) $id;
                continue;
            }

            $post = new Post();
            $post
                ->setBoard($board)
                ->setUploadedBy($bulkUpload->getUploadedBy() ?? $user)
                ->setMimetype($bulkUpload->getMimetype())
                ->setSize($bulkUpload->getSize())
                ->setWidth($bulkUpload->getWidth())
                ->setHeight($bulkUpload->getHeight())
                ->setDuration($bulkUpload->getDuration())
                ->setVector($bulkUpload->getVector())
                ->setPath($newRelativePath)
            ;
            $post->setHasSound($bulkUpload->hasSound()); // Post::setHasSound() returns void (not fluent)
            $manager->persist($post);
            $createdPosts[] = $post;

            // Null the bulk upload path BEFORE removal so postRemove/removeOldFile does NOT
            // unlink the file we just moved into the board directory.
            $bulkUpload->setPath(null);
            $manager->remove($bulkUpload);

            $removedIds[] = (string) $id;
        }

        $manager->flush();

        foreach ($createdPosts as $createdPost) {
            $taggingDispatcher->dispatch($createdPost);
        }

        if ($removedIds !== []) {
            $this->addFlash('notice', $translator->trans('message.bulk_upload_assigned'));
        }

        return $this->json(['removedIds' => $removedIds, 'failedIds' => $failedIds]);
    }

    #[Route(path: '/bulk-upload/delete', name: 'app_bulk_upload_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        BulkUploadRepository $bulkUploadRepository,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('bulk_upload_action', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        $manager = $managerRegistry->getManager();
        $removedIds = [];

        foreach ((array) $request->request->all('ids') as $id) {
            // Ownership scoping: only the uploader can delete their own bulk upload files.
            $bulkUpload = $bulkUploadRepository->findOneBy(['id' => (string) $id, 'uploadedBy' => $user]);
            if ($bulkUpload === null) {
                continue;
            }

            $manager->remove($bulkUpload);
            $removedIds[] = (string) $id;
        }

        $manager->flush();

        if ($removedIds !== []) {
            $this->addFlash('notice', $translator->trans('message.bulk_upload_deleted'));
        }

        return $this->json(['removedIds' => $removedIds]);
    }
}

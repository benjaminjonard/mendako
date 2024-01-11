<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Enum\PaginationType;
use App\Form\Type\BoardType;
use App\Repository\BoardRepository;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use App\Service\PaginatorFactory;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class BoardController extends AbstractController
{
    #[Route(path: '/', name: 'app_board_index', methods: ['GET'])]
    #[Route(path: '/', name: 'app_homepage', methods: ['GET'])]
    public function index(BoardRepository $boardRepository): Response
    {
        $boards = $boardRepository->findBy([], ['name' => Criteria::ASC]);

        return $this->render('App/Board/index.html.twig', [
            'boards' => $boards,
        ]);
    }

    #[Route(path: '/boards/add', name: 'app_board_add', methods: ['GET', 'POST'])]
    public function add(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry
    ): Response {
        $board = new Board();

        $form = $this->createForm(BoardType::class, $board);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $managerRegistry->getManager()->persist($board);
            $managerRegistry->getManager()->flush();

            $this->addFlash('notice', $translator->trans('message.board_added', ['board' => '&nbsp;<strong>'.$board->getName().'</strong>&nbsp;']));

            return $this->redirectToRoute('app_board_show', ['slug' => $board->getSlug()]);
        }

        return $this->render('App/Board/add.html.twig', [
            'board' => $board,
            'form' => $form,
        ]);
    }

    #[Route(path: '/boards/{slug}', name: 'app_board_show', methods: ['GET', 'POST'])]
    public function show(
        Request $request,
        Board $board,
        PostRepository $postRepository,
        TagRepository $tagRepository,
        PaginatorFactory $paginatorFactory,
        #[Autowire('%env(APP_POST_PER_PAGE)%')] int $postPerPage,
        #[Autowire('%env(APP_INFINITE_SCROLL_POST_PER_PAGE)%')] int $infiniteScrollPostPerPage,
    ): Response {
        $page = $request->query->get('page', 1);
        $tags = $request->query->get('tags', '');
        if ($this->getUser()->getPaginationType() === PaginationType::SCROLL->value) {
            $postPerPage = $infiniteScrollPostPerPage;
        }

        if ($request->isXmlHttpRequest()) {
            $posts = $postRepository->filterByTags($board, $tags, $page, $postPerPage);
            $postsHtml = $this->renderView('App/Board/_posts.html.twig', [
                'board' => $board,
                'posts' => $posts,
            ]);

            $tags = $tagRepository->findForPosts($board, $posts);
            $content = json_decode($request->getContent(), true);
            if (isset($content['tags'])) {
                $tags = array_merge($tags, $tagRepository->findByIdForInfiniteScroll($board, $content['tags']));
            }

            $tags = array_unique($tags, SORT_REGULAR);

            $tagsHtml = $this->renderView('App/Board/_tags.html.twig', [
                'board' => $board,
                'tags' => $tags,
            ]);

            return new JsonResponse([
                'posts' => $postsHtml,
                'tags' => $tagsHtml
            ]);
        }

        $posts = $postRepository->filterByTags($board, $tags, $page, $postPerPage);
        $postsCount = $postRepository->countFilterByTags($board, $tags);

        return $this->render('App/Board/show.html.twig', [
            'board' => $board,
            'posts' => $posts,
            'tags' => $tagRepository->findForPosts($board, $posts),
            'paginator' => $paginatorFactory->generate($postsCount),
            'search' => $tags
        ]);
    }

    #[Route(path: '/boards/{slug}/edit', name: 'app_board_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Board $board,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry
    ): Response {
        $form = $this->createForm(BoardType::class, $board);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $managerRegistry->getManager()->persist($board);
            $managerRegistry->getManager()->flush();

            $this->addFlash('notice', $translator->trans('message.board_edited', ['board' => '&nbsp;<strong>'.$board->getName().'</strong>&nbsp;']));

            return $this->redirectToRoute('app_board_show', ['slug' => $board->getSlug()]);
        }

        return $this->render('App/Board/edit.html.twig', [
            'board' => $board,
            'form' => $form,
        ]);
    }

    #[Route(path: '/boards/{slug}/delete', name: 'app_board_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        TranslatorInterface $translator,
        ManagerRegistry $managerRegistry,
        Board $board,
        #[Autowire('%kernel.project_dir%/public/uploads/boards/')] string $boardsUploadsPath
    ): Response {
        $form = $this->createDeleteForm('app_board_delete', $board);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $managerRegistry->getManager()->remove($board);
            $managerRegistry->getManager()->flush();

            $path = $boardsUploadsPath . $board->getId();
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }

            rmdir($path);

            $this->addFlash('notice', $translator->trans('message.board_deleted', ['board' => '&nbsp;<strong>'.$board->getName().'</strong>&nbsp;']));
        }

        return $this->redirectToRoute('app_board_index');
    }
}

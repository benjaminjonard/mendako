<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tag;
use App\Enum\TagCategory;
use App\Form\Type\TagType;
use App\Repository\TagRepository;
use App\Service\PaginatorFactory;
use App\Service\TagMerger;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class TagController extends AbstractController
{
    #[Route(path: '/tags', name: 'app_tag_index', methods: ['GET'])]
    public function index(Request $request, TagRepository $tagRepository, PaginatorFactory $paginatorFactory): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $search = trim($request->query->getString('q'));
        $category = TagCategory::tryFrom($request->query->getString('category'));

        $sort = $request->query->getString('sort', 'name');
        $direction = strtoupper($request->query->getString('dir')) === 'DESC' ? 'DESC' : 'ASC';

        $perPage = $paginatorFactory->getPaginationItemsPerPage();

        return $this->render('App/Tag/index.html.twig', [
            'tags' => $tagRepository->findPaginated($page, $perPage, $search, $category, $sort, $direction),
            'paginator' => $paginatorFactory->generate($tagRepository->countFiltered($search, $category)),
            'search' => $search,
            'category' => $category,
            'sort' => $sort,
            'direction' => $direction,
            'categories' => TagCategory::cases(),
        ]);
    }

    #[Route(path: '/tags/autocomplete', name: 'app_tag_autocomplete', methods: ['GET'])]
    public function add(
        Request $request,
        TagRepository $tagRepository
    ): Response {
        $query = $request->query->get('query', null);

        $tags = array_map(static function (Tag $tag) : array {
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
            'form' => $form,
        ]);
    }

    #[Route(path: '/tags/{id}/merge', name: 'app_tag_merge', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function merge(
        Request $request,
        TranslatorInterface $translator,
        TagRepository $tagRepository,
        TagMerger $tagMerger,
        Tag $tag,
    ): Response {
        if (!$this->isCsrfTokenValid('tag_merge', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $names = array_unique(array_filter(
            preg_split('/\s+/', trim($request->request->getString('tags'))) ?: [],
            static fn (string $name): bool => $name !== '',
        ));

        $sources = [];
        foreach ($names as $name) {
            $source = $tagRepository->findOneBy(['name' => $name]);
            if ($source !== null && $source->getId() !== $tag->getId()) {
                $sources[] = $source;
            }
        }

        $merged = $tagMerger->merge($tag, $sources);

        if ($merged > 0) {
            $this->addFlash('notice', $translator->trans('message.tags_merged', [
                'count' => $merged,
                'tag' => '&nbsp;<strong>'.$tag->getName().'</strong>&nbsp;',
            ]));
        }

        return $this->redirectToRoute('app_tag_index');
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

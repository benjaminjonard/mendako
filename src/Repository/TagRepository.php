<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\Post;
use App\Entity\Tag;
use App\Enum\TagCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class TagRepository extends ServiceEntityRepository
{
    /**
     * The sortable columns exposed on the tag index, mapped to their DQL ordering expression.
     * 'count' targets the correlated post-count alias built in findPaginated().
     */
    private const array SORTS = [
        'name' => 'LOWER(t.name)',
        'count' => 'counter',
        'category' => 't.category',
        'suggested' => 't.suggested',
        'created' => 't.createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    public function findPaginated(
        int $page,
        int $perPage,
        ?string $search,
        ?TagCategory $category,
        string $sort,
        string $direction,
    ): array {
        $countQuery = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT i2.id)')
            ->from(Tag::class, 't2')
            ->join('t2.posts', 'i2')
            ->where('t2 = t')
            ->getDQL()
        ;

        $qb = $this->createQueryBuilder('t')
            ->select("t.id, t.name, t.category, t.suggested, t.createdAt, ({$countQuery}) AS counter")
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
        ;

        $this->applyFilters($qb, $search, $category);

        $column = self::SORTS[$sort] ?? self::SORTS['name'];
        $direction = strtoupper($direction) === 'DESC' ? Criteria::DESC : Criteria::ASC;
        $qb->orderBy($column, $direction);
        if ($column !== self::SORTS['name']) {
            $qb->addOrderBy('LOWER(t.name)', Criteria::ASC); // stable tiebreaker
        }

        return $qb->getQuery()->getArrayResult();
    }

    public function countFiltered(?string $search, ?TagCategory $category): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)');
        $this->applyFilters($qb, $search, $category);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function applyFilters(QueryBuilder $qb, ?string $search, ?TagCategory $category): void
    {
        if ($search !== null && trim($search) !== '') {
            $qb->andWhere('LOWER(t.name) LIKE LOWER(:search)')
                ->setParameter('search', '%'.trim($search).'%');
        }

        if ($category !== null) {
            $qb->andWhere('t.category = :category')->setParameter('category', $category->value);
        }
    }

    public function findForPosts(Board $board, array $posts): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        // Semi-join rather than a correlated count: the board-wide tally is aggregated once for
        // every tag, instead of being recomputed for each (tag, post) pair the join produces.
        $onAPagePost = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('1')
            ->from(Post::class, 'p2')
            ->join('p2.tags', 't2')
            ->where('p2 IN (:posts)')
            ->andWhere('t2 = t')
            ->getDQL()
        ;

        $qb
            ->select('t.id, t.name, t.category, COUNT(p.id) AS counter')
            ->from(Tag::class, 't')
            ->join('t.posts', 'p', 'WITH', 'p.board = :board')
            ->where($qb->expr()->exists($onAPagePost))
            ->groupBy('t.id, t.name, t.category')
            ->setParameter('posts', $posts)
            ->setParameter('board', $board->getId())
        ;

        return $this->withCategoryValues($qb->getQuery()->getArrayResult());
    }

    public function findByIdForInfiniteScroll(Board $board, array $ids): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('t.id, t.name, t.category, COUNT(p.id) AS counter')
            ->from(Tag::class, 't')
            ->join('t.posts', 'p', 'WITH', 'p.board = :board')
            ->where('t.id IN (:ids)')
            ->groupBy('t.id, t.name, t.category')
            ->setParameter('ids', $ids)
            ->setParameter('board', $board->getId())
        ;

        return $this->withCategoryValues($qb->getQuery()->getArrayResult());
    }

    private function withCategoryValues(array $results): array
    {
        foreach ($results as &$result) {
            $result['category'] = $result['category']?->value;
        }

        return $results;
    }

    /**
     * Flip the given names from `custom` to the model source that emitted them. Only
     * `custom` rows are touched, so a name already attributed to another model keeps its first
     * attribution. Returns rows reclassified. Bulk UPDATE — run it when no matching Tag is held in
     * the UoW.
     */
    public function reclassifyToModel(array $names, string $source): int
    {
        if ($names === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.source', ':source')
            ->where('t.name IN (:names)')
            ->andWhere('t.source = :custom')
            ->setParameter('source', $source)
            ->setParameter('names', $names)
            ->setParameter('custom', Tag::SOURCE_CUSTOM)
            ->getQuery()
            ->execute();
    }

    public function findLike(string $query)
    {
        return $this
            ->createQueryBuilder('tag')
            ->addSelect('(CASE WHEN LOWER(tag.name) LIKE LOWER(:startWith) THEN 0 ELSE 1 END) AS HIDDEN startWithOrder')
            ->andWhere('LOWER(tag.name) LIKE LOWER(:query)')
            ->orderBy('startWithOrder', Criteria::ASC) // Order tags starting with the search term first
            ->addOrderBy('LOWER(tag.name)', Criteria::ASC) // Then order other matching tags alphabetically
            ->setParameter('query', '%'.trim($query).'%')
            ->setParameter('startWith', trim($query).'%')
            ->setMaxResults(15)
            ->getQuery()
            ->getResult()
        ;
    }
}

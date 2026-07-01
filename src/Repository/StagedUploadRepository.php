<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StagedUpload;
use App\Entity\TagSuggestion;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StagedUploadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StagedUpload::class);
    }

    /**
     * Stream every staged upload (for `--all` retroactive tagging). `toIterable()` so a
     * large staging backlog isn't fully hydrated at once.
     *
     * @return iterable<StagedUpload>
     */
    public function findAllIterable(): iterable
    {
        return $this->createQueryBuilder('s')->getQuery()->toIterable();
    }

    /**
     * Stream staged uploads with no automatic tagging suggestion yet (never processed) — the default
     * retroactive set. Correlated NOT EXISTS on (target_type='staged', target_id = id).
     *
     * @return iterable<StagedUpload>
     */
    public function findWithoutSuggestionsIterable(): iterable
    {
        $qb = $this->createQueryBuilder('s');
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(TagSuggestion::class, 'ts')
            ->where('ts.targetType = :targetType')
            ->andWhere('ts.targetId = s.id');

        return $qb
            ->where($qb->expr()->not($qb->expr()->exists($sub->getDQL())))
            ->setParameter('targetType', 'staged')
            ->getQuery()
            ->toIterable();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('s')->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Number of staged uploads with no automatic tagging suggestion yet (the un-processed backlog).
     */
    public function countWithoutSuggestions(): int
    {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)');
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(TagSuggestion::class, 'ts')
            ->where('ts.targetType = :targetType')
            ->andWhere('ts.targetId = s.id');

        return (int) $qb
            ->where($qb->expr()->not($qb->expr()->exists($sub->getDQL())))
            ->setParameter('targetType', 'staged')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return StagedUpload[]
     */
    public function findAllForUser(User $user): array
    {
        return $this
            ->createQueryBuilder('staged')
            ->where('staged.uploadedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('staged.createdAt', \Doctrine\Common\Collections\Criteria::DESC)
            ->getQuery()
            ->getResult();
    }
}

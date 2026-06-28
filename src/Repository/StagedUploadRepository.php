<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StagedUpload;
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

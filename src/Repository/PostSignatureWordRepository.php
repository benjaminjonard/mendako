<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PostSignatureWord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PostSignatureWordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostSignatureWord::class);
    }
}

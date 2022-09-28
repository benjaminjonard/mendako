<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Post;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByUsernameOrEmail($login): ?User
    {
        $user = $this
            ->createQueryBuilder('u')
            ->where('u.username = :login OR u.email = :login')
            ->setParameter('login', $login)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        if (null === $user) {
            throw new UserNotFoundException();
        }

        return $user;
    }
}

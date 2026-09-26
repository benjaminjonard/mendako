<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tag;
use Doctrine\ORM\EntityManagerInterface;

class TagMerger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function merge(Tag $target, array $sources): int
    {
        $merged = 0;
        $seen = [];

        foreach ($sources as $source) {
            $id = $source->getId();

            if ($id === $target->getId() || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($source->getPosts()->toArray() as $post) {
                $post->addTag($target);
                $post->removeTag($source);
            }

            $this->entityManager->remove($source);
            ++$merged;
        }

        if ($merged > 0) {
            $this->entityManager->flush();
        }

        return $merged;
    }
}

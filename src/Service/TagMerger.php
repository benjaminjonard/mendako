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

    /**
     * Absorb $sources into $target: every post carrying a source tag is moved onto the target
     * tag, then the source tag is deleted. The target tag itself and duplicate entries are
     * ignored. Returns the number of tags actually merged.
     *
     * @param Tag[] $sources
     */
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

            // Snapshot the collection: reassigning mutates the one we iterate over.
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

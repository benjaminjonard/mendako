<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Post;
use App\Repository\TagRepository;

class AutomatedTagger
{
    public function __construct(private readonly TagRepository $tagRepository)
    {
    }

    public function tag(Post $post): void
    {
        if ($post->getMimetype() === 'video/mp4') {
            $automatedTag = $this->tagRepository->findOneBy(['name' => 'animated']);
            $post->addTag($automatedTag);
        }
    }
}

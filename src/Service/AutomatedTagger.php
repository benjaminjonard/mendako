<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Image;
use App\Repository\TagRepository;

class AutomatedTagger
{
    public function __construct(private readonly TagRepository $tagRepository)
    {
    }

    public function tag(Image $image): void
    {
        if ($image->getMimetype() === 'video/mp4') {
            $automatedTag = $this->tagRepository->findOneBy(['slug' => 'animated']);
            $image->addTag($automatedTag);
        }
    }
}

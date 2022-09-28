<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Post;
use App\Repository\TagRepository;
use FFMpeg\FFMpeg;

class AutomatedTagger
{
    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly string $publicPath
    ) {
    }

    public function tag(Post $post): void
    {
        if ($post->getMimetype() === 'video/mp4') {
           $this->animated($post);
           $this->withSound($post);
        }
    }

    private function animated(Post $post): void
    {
        $automatedTag = $this->tagRepository->findOneBy(['name' => 'animated']);
        $post->addTag($automatedTag);
    }

    private function withSound(Post $post): void
    {
        $ffmpeg = FFMpeg::create();
        $video = $ffmpeg->open($this->publicPath.'/'.$post->getPath());
        if ($video->getStreams()->audios()->first()) {
            $withSound = $this->tagRepository->findOneBy(['name' => 'with_sound']);
            $post->addTag($withSound);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Post;
use App\Repository\TagRepository;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\Stream;

class AutomatedTagger
{
    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly string $publicPath
    ) {
    }

    public function tag(Post $post): void
    {
        if ($post->getMimetype() === 'video/mp4' || $post->getMimetype() === 'video/webm') {
            $this->animated($post);
            $this->withSound($post);
            $this->video($post);
        }

        if ($post->getMimetype() === 'image/gif') {
            $this->animated($post);
            $this->gif($post);
        }
    }

    private function animated(Post $post): void
    {
        $automatedTag = $this->tagRepository->findOneBy(['name' => 'animated']);
        $post->addTag($automatedTag);
    }

    private function gif(Post $post): void
    {
        $gifTag = $this->tagRepository->findOneBy(['name' => 'gif']);
        $post->addTag($gifTag);
    }

    private function video(Post $post): void
    {
        $videoTag = $this->tagRepository->findOneBy(['name' => 'video']);
        $post->addTag($videoTag);
    }

    private function withSound(Post $post): void
    {
        $ffmpeg = FFMpeg::create();
        $video = $ffmpeg->open($this->publicPath.'/'.$post->getPath());
        if ($video->getStreams()->audios()->first() instanceof Stream) {
            $withSound = $this->tagRepository->findOneBy(['name' => 'with_sound']);
            $post->addTag($withSound);
        }
    }
}

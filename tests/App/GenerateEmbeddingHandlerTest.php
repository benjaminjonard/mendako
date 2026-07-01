<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Post;
use App\Message\GenerateEmbeddingMessage;
use App\MessageHandler\GenerateEmbeddingHandler;
use App\Repository\EmbeddingRepository;
use App\Repository\PostRepository;
use App\Repository\StagedUploadRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\ThumbnailGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GenerateEmbeddingHandlerTest extends TestCase
{
    private function provider(bool $enabled, ?string $wdModel = 'wd-eva02-large-tagger-v3'): AutoTagConfigProvider
    {
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);
        $provider->method('getActiveModel')->willReturnMap([['wd', $wdModel]]);

        return $provider;
    }

    private function handler(AutoTagConfigProvider $provider, PostRepository $postRepository, AutoTagInferenceClient $client, ?EmbeddingRepository $embeddingRepository = null, ?ThumbnailGenerator $thumbnailGenerator = null): GenerateEmbeddingHandler
    {
        return new GenerateEmbeddingHandler(
            $postRepository,
            $this->createStub(StagedUploadRepository::class),
            $provider,
            $client,
            $thumbnailGenerator ?? $this->thumbnailGeneratorThatWrites(),
            $embeddingRepository ?? $this->createStub(EmbeddingRepository::class),
            '/tmp',
            new NullLogger(),
        );
    }

    /** A generator whose generate() actually creates the thumbnail file the handler embeds. */
    private function thumbnailGeneratorThatWrites(): ThumbnailGenerator
    {
        $generator = $this->createStub(ThumbnailGenerator::class);
        $generator->method('generate')->willReturnCallback(static function (string $path, string $destPath): bool {
            touch($destPath);

            return true;
        });

        return $generator;
    }

    private function postWithPath(): Post
    {
        $post = new Post();
        $post->setPath('uploads/boards/1/x.png');

        return $post;
    }

    public function test_does_nothing_when_feature_disabled(): void
    {
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('embed');

        $handler = $this->handler($this->provider(false), $this->createStub(PostRepository::class), $client);
        $handler(new GenerateEmbeddingMessage('post', 'id'));
    }

    public function test_skips_when_no_embedding_model(): void
    {
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('embed');

        $handler = $this->handler($this->provider(true, null), $this->createStub(PostRepository::class), $client);
        $handler(new GenerateEmbeddingMessage('post', 'id'));
    }

    public function test_stores_image_embedding(): void
    {
        $post = $this->postWithPath();
        $post->setMimetype('image/png');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->once())->method('embed')
            ->with($this->anything(), 'wd-eva02-large-tagger-v3')
            ->willReturn(['embedding' => [0.6, 0.8], 'dim' => 2, 'model_id' => 'wd-eva02-large-tagger-v3']);
        $embeddings = $this->createMock(EmbeddingRepository::class);
        $embeddings->expects($this->once())->method('replaceForTarget')
            ->with('post', 'some-id', 'wd-eva02-large-tagger-v3', ['[0.6,0.8]']);

        $handler = $this->handler($this->provider(true), $postRepository, $client, embeddingRepository: $embeddings);
        $handler(new GenerateEmbeddingMessage('post', 'some-id'));
    }

    public function test_stores_one_embedding_per_video_frame(): void
    {
        $post = $this->postWithPath();
        $post->setMimetype('video/mp4');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        $thumbnailGenerator = $this->createStub(ThumbnailGenerator::class);
        $thumbnailGenerator->method('extractVideoFrames')->willReturn(['/tmp/f0.jpeg', '/tmp/f1.jpeg', '/tmp/f2.jpeg']);

        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->exactly(3))->method('embed')->willReturnOnConsecutiveCalls(
            ['embedding' => [0.1, 0.2], 'dim' => 2, 'model_id' => 'wd-eva02-large-tagger-v3'],
            ['embedding' => [0.3, 0.4], 'dim' => 2, 'model_id' => 'wd-eva02-large-tagger-v3'],
            ['embedding' => [0.5, 0.6], 'dim' => 2, 'model_id' => 'wd-eva02-large-tagger-v3'],
        );
        $embeddings = $this->createMock(EmbeddingRepository::class);
        $embeddings->expects($this->once())->method('replaceForTarget')
            ->with('post', 'vid', 'wd-eva02-large-tagger-v3', ['[0.1,0.2]', '[0.3,0.4]', '[0.5,0.6]']);

        $handler = $this->handler($this->provider(true), $postRepository, $client, embeddingRepository: $embeddings, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateEmbeddingMessage('post', 'vid'));
    }

    public function test_skips_when_no_thumbnail_produced(): void
    {
        $post = $this->postWithPath();
        $post->setMimetype('image/svg+xml');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        // generate() reports success but writes no file (e.g. SVG) → /embed must not be called.
        $noFileGenerator = $this->createStub(ThumbnailGenerator::class);
        $noFileGenerator->method('generate')->willReturn(true);

        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('embed');
        $embeddings = $this->createMock(EmbeddingRepository::class);
        $embeddings->expects($this->never())->method('replaceForTarget');

        $handler = $this->handler($this->provider(true), $postRepository, $client, embeddingRepository: $embeddings, thumbnailGenerator: $noFileGenerator);
        $handler(new GenerateEmbeddingMessage('post', 'some-id'));
    }

    public function test_does_not_store_when_service_returns_empty(): void
    {
        $post = $this->postWithPath();
        $post->setMimetype('image/png');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->once())->method('embed')->willReturn([]);
        $embeddings = $this->createMock(EmbeddingRepository::class);
        $embeddings->expects($this->never())->method('replaceForTarget');

        $handler = $this->handler($this->provider(true), $postRepository, $client, embeddingRepository: $embeddings);
        $handler(new GenerateEmbeddingMessage('post', 'some-id'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Post;
use App\Entity\StagedUpload;
use App\Entity\TagSuggestion;
use App\Message\GenerateSuggestionsMessage;
use App\MessageHandler\GenerateSuggestionsHandler;
use App\Repository\PostRepository;
use App\Repository\StagedUploadRepository;
use App\Repository\TagRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\AutoTag\FrameResultAggregator;
use App\Service\AutoTag\KnnSuggestionService;
use App\Service\AutoTag\SuggestionService;
use App\Service\ThumbnailGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GenerateSuggestionsHandlerTest extends TestCase
{
    private function provider(bool $enabled, ?string $wdModel = 'wd-eva02-large-tagger-v3', ?string $clipModel = null): AutoTagConfigProvider
    {
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);
        $provider->method('getActiveModel')->willReturnMap([
            ['wd', $wdModel],
            ['clip', $clipModel],
        ]);

        return $provider;
    }

    private function handler(AutoTagConfigProvider $provider, PostRepository $postRepository, StagedUploadRepository $stagedRepository, AutoTagInferenceClient $client, ?SuggestionService $suggestionService = null, ?EntityManagerInterface $entityManager = null, ?KnnSuggestionService $knnSuggestionService = null, ?TagRepository $tagRepository = null, ?ThumbnailGenerator $thumbnailGenerator = null): GenerateSuggestionsHandler
    {
        $tagStub = $tagRepository;
        if ($tagStub === null) {
            $tagStub = $this->createStub(TagRepository::class);
            $tagStub->method('findAllNames')->willReturn([]);
        }

        return new GenerateSuggestionsHandler(
            $postRepository,
            $stagedRepository,
            $provider,
            $client,
            $suggestionService ?? $this->createStub(SuggestionService::class),
            $knnSuggestionService ?? $this->createStub(KnnSuggestionService::class),
            $tagStub,
            $thumbnailGenerator ?? $this->createStub(ThumbnailGenerator::class),
            new FrameResultAggregator(),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            '/tmp',
            new NullLogger(),
        );
    }

    private function postWithPath(): Post
    {
        $post = new Post();
        $post->setPath('uploads/boards/1/x.png');

        return $post;
    }

    public function test_analyzes_existing_post(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->once())->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));
    }

    public function test_stores_suggestions_when_analysis_produces_results(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $result = ['tags' => [['name' => '1girl', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => 'general', 'score' => 0.8]];
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn($result);
        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->once())->method('store')->with('post', 'some-id', $result);

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client, $suggestionService);
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));
    }

    public function test_does_not_store_when_result_is_empty(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);
        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->never())->method('store');

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client, $suggestionService);
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));
    }

    public function test_skips_when_disabled(): void
    {
        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->never())->method('find');
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(false), $postRepository, $this->createStub(StagedUploadRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'id', 'hash'));
    }

    public function test_skips_when_item_missing(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn(null);
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'missing', 'hash'));
    }

    public function test_skips_when_no_active_wd_model(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(true, null), $postRepository, $this->createStub(StagedUploadRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'id', 'hash'));
    }

    public function test_stores_clip_embedding_when_clip_model_active(): void
    {
        $post = $this->postWithPath();
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->once())->method('analyze')
            ->with($this->anything(), 'wd-eva02-large-tagger-v3', 'siglip2-so400m', $this->anything())
            ->willReturn([
                'tags' => [],
                'rating' => ['label' => null, 'score' => 0.0],
                'embedding' => [0.6, 0.8],
                'clip_model_id' => 'siglip2-so400m',
            ]);

        $handler = $this->handler($this->provider(true, clipModel: 'siglip2-so400m'), $postRepository, $this->createStub(StagedUploadRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));

        $this->assertSame('[0.6,0.8]', $post->getClipVector());
        $this->assertSame('siglip2-so400m', $post->getClipModelId());
    }

    public function test_does_not_store_embedding_when_no_clip_model(): void
    {
        $post = $this->postWithPath();
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);
        $client = $this->createMock(AutoTagInferenceClient::class);
        // No clip model active → analyze called with null, result carries no embedding.
        $client->expects($this->once())->method('analyze')
            ->with($this->anything(), 'wd-eva02-large-tagger-v3', null, [])
            ->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));

        $this->assertNull($post->getClipVector());
        $this->assertNull($post->getClipModelId());
    }

    public function test_video_samples_frames_and_stores_aggregated_suggestions(): void
    {
        $post = new Post();
        $post->setPath('uploads/boards/1/clip.mp4')->setMimetype('video/mp4');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        $thumbnailGenerator = $this->createStub(ThumbnailGenerator::class);
        $thumbnailGenerator->method('extractVideoFrames')->willReturn(['/tmp/f0.jpeg', '/tmp/f1.jpeg', '/tmp/f2.jpeg']);

        // Each frame returns 'cat'; the middle frame scores it highest → aggregated max 0.9.
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->exactly(3))->method('analyze')->willReturnOnConsecutiveCalls(
            ['tags' => [['name' => 'cat', 'category' => 'general', 'score' => 0.4]], 'rating' => ['label' => null, 'score' => 0.0]],
            ['tags' => [['name' => 'cat', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => null, 'score' => 0.0]],
            ['tags' => [['name' => 'cat', 'category' => 'general', 'score' => 0.6]], 'rating' => ['label' => null, 'score' => 0.0]],
        );

        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->once())->method('store')->with(
            'post',
            'video-id',
            $this->callback(static fn (array $result): bool => $result['tags'] === [['name' => 'cat', 'category' => 'general', 'score' => 0.9]]),
        );

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client, $suggestionService, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('post', 'video-id', 'hash'));
    }

    public function test_video_falls_back_to_single_frame_when_no_frames_extracted(): void
    {
        $post = new Post();
        $post->setPath('uploads/x.mp4')->setMimetype('video/mp4');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        $thumbnailGenerator = $this->createMock(ThumbnailGenerator::class);
        $thumbnailGenerator->method('extractVideoFrames')->willReturn([]); // sampler couldn't read it
        $thumbnailGenerator->expects($this->once())->method('generate'); // fallback single frame

        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->once())->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('post', 'video-id', 'hash'));
    }

    public function test_video_extraction_failure_is_swallowed(): void
    {
        $post = new Post();
        $post->setPath('uploads/x.mp4')->setMimetype('video/mp4');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        $thumbnailGenerator = $this->createStub(ThumbnailGenerator::class);
        $thumbnailGenerator->method('extractVideoFrames')->willThrowException(new \RuntimeException('ffmpeg boom'));
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client, thumbnailGenerator: $thumbnailGenerator);

        // Must not throw — extraction failure soft-fails (analyze never reached, asserted above).
        $handler(new GenerateSuggestionsMessage('post', 'video-id', 'hash'));
    }

    public function test_representative_frame_empty_keeps_other_frames_tags(): void
    {
        $post = new Post();
        $post->setPath('uploads/x.mp4')->setMimetype('video/mp4');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        $thumbnailGenerator = $this->createStub(ThumbnailGenerator::class);
        $thumbnailGenerator->method('extractVideoFrames')->willReturn(['/tmp/f0.jpeg', '/tmp/f1.jpeg', '/tmp/f2.jpeg']);

        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturnOnConsecutiveCalls(
            ['tags' => [['name' => 'cat', 'category' => 'general', 'score' => 0.5]], 'rating' => ['label' => null, 'score' => 0.0]],
            [], // representative (middle) frame soft-failed
            ['tags' => [['name' => 'dog', 'category' => 'general', 'score' => 0.6]], 'rating' => ['label' => null, 'score' => 0.0]],
        );

        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->once())->method('store')->with(
            'post',
            'video-id',
            $this->callback(static fn (array $result): bool => $result['tags'] === [
                ['name' => 'dog', 'category' => 'general', 'score' => 0.6],
                ['name' => 'cat', 'category' => 'general', 'score' => 0.5],
            ] && !array_key_exists('embedding', $result)),
        );

        $handler = $this->handler($this->provider(true, clipModel: 'siglip2-so400m'), $postRepository, $this->createStub(StagedUploadRepository::class), $client, $suggestionService, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('post', 'video-id', 'hash'));
    }

    public function test_image_item_uses_single_thumbnail_not_frame_extraction(): void
    {
        $post = $this->postWithPath();
        $post->setMimetype('image/png');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        $thumbnailGenerator = $this->createMock(ThumbnailGenerator::class);
        $thumbnailGenerator->expects($this->never())->method('extractVideoFrames');
        $thumbnailGenerator->expects($this->once())->method('generate');

        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('post', 'image-id', 'hash'));
    }

    public function test_stores_zeroshot_suggestions_as_clip_source(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn([
            'tags' => [],
            'rating' => ['label' => null, 'score' => 0.0],
            'zeroshot' => [['name' => 'red_fox', 'score' => 0.4], ['name' => 'snow', 'score' => 0.3]],
        ]);
        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->once())->method('store')->with(
            'post',
            'some-id',
            [
                'tags' => [
                    ['name' => 'red_fox', 'score' => 0.4, 'category' => null],
                    ['name' => 'snow', 'score' => 0.3, 'category' => null],
                ],
                'rating' => ['label' => null],
            ],
            TagSuggestion::SOURCE_CLIP,
        );

        $handler = $this->handler($this->provider(true, clipModel: 'siglip2-so400m'), $postRepository, $this->createStub(StagedUploadRepository::class), $client, $suggestionService);
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));
    }

    public function test_zeroshot_storage_failure_does_not_throw(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0], 'zeroshot' => [['name' => 'red_fox', 'score' => 0.4]]]);
        $suggestionService = $this->createStub(SuggestionService::class);
        $suggestionService->method('store')->willThrowException(new \RuntimeException('store failed'));

        $handler = $this->handler($this->provider(true, clipModel: 'siglip2-so400m'), $postRepository, $this->createStub(StagedUploadRepository::class), $client, $suggestionService);

        $this->expectNotToPerformAssertions();
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));
    }

    public function test_propagates_knn_when_embedding_stored(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0], 'embedding' => [0.6, 0.8], 'clip_model_id' => 'siglip2-so400m']);
        $knn = $this->createMock(KnnSuggestionService::class);
        $knn->expects($this->once())->method('propagate')->with('post', 'some-id', '[0.6,0.8]', 'siglip2-so400m');

        $handler = $this->handler($this->provider(true, clipModel: 'siglip2-so400m'), $postRepository, $this->createStub(StagedUploadRepository::class), $client, knnSuggestionService: $knn);
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));
    }

    public function test_does_not_propagate_knn_without_embedding(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [['name' => '1girl', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => null, 'score' => 0.0]]);
        $knn = $this->createMock(KnnSuggestionService::class);
        $knn->expects($this->never())->method('propagate');

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedUploadRepository::class), $client, knnSuggestionService: $knn);
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));
    }

    public function test_knn_failure_does_not_throw(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0], 'embedding' => [0.6, 0.8], 'clip_model_id' => 'siglip2-so400m']);
        $knn = $this->createStub(KnnSuggestionService::class);
        $knn->method('propagate')->willThrowException(new \RuntimeException('knn query failed'));

        $handler = $this->handler($this->provider(true, clipModel: 'siglip2-so400m'), $postRepository, $this->createStub(StagedUploadRepository::class), $client, knnSuggestionService: $knn);

        // Must not throw — kNN is best-effort.
        $this->expectNotToPerformAssertions();
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));
    }

    public function test_embedding_flush_failure_does_not_block_suggestions(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $result = ['tags' => [['name' => '1girl', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => null, 'score' => 0.0], 'embedding' => [0.6, 0.8], 'clip_model_id' => 'siglip2-so400m'];
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn($result);
        // Suggestions must be stored even though the embedding flush blows up.
        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->once())->method('store')->with('post', 'some-id', $result);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('vector dimension mismatch'));

        $handler = $this->handler($this->provider(true, clipModel: 'siglip2-so400m'), $postRepository, $this->createStub(StagedUploadRepository::class), $client, $suggestionService, $em);

        // Must not throw — the embedding failure is swallowed.
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));
    }

    public function test_ignores_malformed_embedding(): void
    {
        $post = $this->postWithPath();
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0], 'embedding' => []]);

        $handler = $this->handler($this->provider(true, clipModel: 'siglip2-so400m'), $postRepository, $this->createStub(StagedUploadRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'some-id', 'hash'));

        $this->assertNull($post->getClipVector());
    }

    public function test_resolves_staged_item_via_staged_repository(): void
    {
        $staged = new StagedUpload();
        $staged->setPath('uploads/staging/y.png');
        $stagedRepository = $this->createMock(StagedUploadRepository::class);
        $stagedRepository->expects($this->once())->method('find')->with('staged-id')->willReturn($staged);
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);

        $handler = $this->handler($this->provider(true), $this->createStub(PostRepository::class), $stagedRepository, $client);
        $handler(new GenerateSuggestionsMessage('staged', 'staged-id', 'hash'));
    }
}

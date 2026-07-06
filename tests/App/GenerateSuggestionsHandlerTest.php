<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Post;
use App\Entity\StagedPost;
use App\Message\GenerateSuggestionsMessage;
use App\MessageHandler\GenerateSuggestionsHandler;
use App\Repository\PostRepository;
use App\Repository\StagedPostRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\AutoTag\FrameResultAggregator;
use App\Service\AutoTag\SuggestionService;
use App\Service\ThumbnailGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GenerateSuggestionsHandlerTest extends TestCase
{
    private function provider(bool $enabled, ?string $wdModel = 'wd-eva02-large-tagger-v3'): AutoTagConfigProvider
    {
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);
        $provider->method('getActiveModel')->willReturnMap([
            ['wd', $wdModel],
        ]);

        return $provider;
    }

    private function handler(AutoTagConfigProvider $provider, PostRepository $postRepository, StagedPostRepository $stagedPostRepository, AutoTagInferenceClient $client, ?SuggestionService $suggestionService = null, ?ThumbnailGenerator $thumbnailGenerator = null): GenerateSuggestionsHandler
    {
        return new GenerateSuggestionsHandler(
            $postRepository,
            $stagedPostRepository,
            $provider,
            $client,
            $suggestionService ?? $this->createStub(SuggestionService::class),
            $thumbnailGenerator ?? $this->createStub(ThumbnailGenerator::class),
            new FrameResultAggregator(),
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

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedPostRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'some-id'));
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

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedPostRepository::class), $client, $suggestionService);
        $handler(new GenerateSuggestionsMessage('post', 'some-id'));
    }

    public function test_does_not_store_when_result_is_empty(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);
        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->never())->method('store');

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedPostRepository::class), $client, $suggestionService);
        $handler(new GenerateSuggestionsMessage('post', 'some-id'));
    }

    public function test_skips_when_disabled(): void
    {
        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->never())->method('find');
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(false), $postRepository, $this->createStub(StagedPostRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'id'));
    }

    public function test_skips_when_item_missing(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn(null);
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedPostRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'missing'));
    }

    public function test_skips_when_no_active_wd_model(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(true, null), $postRepository, $this->createStub(StagedPostRepository::class), $client);
        $handler(new GenerateSuggestionsMessage('post', 'id'));
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

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedPostRepository::class), $client, $suggestionService, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('post', 'video-id'));
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

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedPostRepository::class), $client, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('post', 'video-id'));
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

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedPostRepository::class), $client, thumbnailGenerator: $thumbnailGenerator);

        // Must not throw — extraction failure soft-fails (analyze never reached, asserted above).
        $handler(new GenerateSuggestionsMessage('post', 'video-id'));
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
            ]),
        );

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedPostRepository::class), $client, $suggestionService, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('post', 'video-id'));
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

        $handler = $this->handler($this->provider(true), $postRepository, $this->createStub(StagedPostRepository::class), $client, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('post', 'image-id'));
    }

    public function test_resolves_bulk_upload_item_via_bulk_upload_repository(): void
    {
        $stagedPost = new StagedPost();
        $stagedPost->setPath('uploads/bulk-upload/y.png');
        $stagedPostRepository = $this->createMock(StagedPostRepository::class);
        $stagedPostRepository->expects($this->once())->method('find')->with('bulk-upload-id')->willReturn($stagedPost);
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);

        $handler = $this->handler($this->provider(true), $this->createStub(PostRepository::class), $stagedPostRepository, $client);
        $handler(new GenerateSuggestionsMessage('bulk', 'bulk-upload-id'));
    }
}

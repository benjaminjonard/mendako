<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Post;
use App\Message\GenerateSuggestionsMessage;
use App\MessageHandler\GenerateSuggestionsHandler;
use App\Repository\PostRepository;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use App\Service\AutoTag\FrameResultAggregator;
use App\Service\AutoTag\SuggestionService;
use App\Service\ThumbnailGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GenerateSuggestionsHandlerTest extends TestCase
{
    private const array WD_ONLY = ['wd' => 'wd-eva02-large-tagger-v3'];

    private function provider(bool $enabled, array $models = self::WD_ONLY): AutoTagConfigProvider
    {
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);
        $provider->method('getModelsForBoard')->willReturn($models);

        return $provider;
    }

    private function handler(AutoTagConfigProvider $provider, PostRepository $postRepository, AutoTagInferenceClient $client, ?SuggestionService $suggestionService = null, ?ThumbnailGenerator $thumbnailGenerator = null): GenerateSuggestionsHandler
    {
        return new GenerateSuggestionsHandler(
            $postRepository,
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

        $handler = $this->handler($this->provider(true), $postRepository, $client);
        $handler(new GenerateSuggestionsMessage('some-id'));
    }

    public function test_stores_suggestions_when_analysis_produces_results(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $result = ['tags' => [['name' => '1girl', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => 'general', 'score' => 0.8]];
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn($result);
        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->once())->method('store')->with('post', 'some-id', $result, 'wd');

        $handler = $this->handler($this->provider(true), $postRepository, $client, $suggestionService);
        $handler(new GenerateSuggestionsMessage('some-id'));
    }

    public function test_does_not_store_when_result_is_empty(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);
        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->never())->method('store');

        $handler = $this->handler($this->provider(true), $postRepository, $client, $suggestionService);
        $handler(new GenerateSuggestionsMessage('some-id'));
    }

    public function test_skips_when_disabled(): void
    {
        $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->never())->method('find');
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(false), $postRepository, $client);
        $handler(new GenerateSuggestionsMessage('id'));
    }

    public function test_skips_when_item_missing(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn(null);
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(true), $postRepository, $client);
        $handler(new GenerateSuggestionsMessage('missing'));
    }

    public function test_skips_when_board_has_no_model(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->never())->method('analyze');

        $handler = $this->handler($this->provider(true, []), $postRepository, $client);
        $handler(new GenerateSuggestionsMessage('id'));
    }

    public function test_runs_every_model_configured_for_the_board(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());

        $wdResult = ['tags' => [['name' => '1girl', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => 'general', 'score' => 0.8]];
        $ramResult = ['tags' => [['name' => 'beach', 'category' => 'general', 'score' => 0.7]], 'rating' => ['label' => null, 'score' => 0.0]];

        // One call per model, each against the same decoded thumbnail.
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->exactly(2))->method('analyze')->willReturnOnConsecutiveCalls($wdResult, $ramResult);

        // Each model's output is stored under its own source, so the two never overwrite each other.
        $stored = [];
        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->exactly(2))->method('store')->willReturnCallback(
            static function (string $targetType, string $targetId, array $result, string $source) use (&$stored): void {
                $stored[$source] = $result;
            },
        );

        $handler = $this->handler($this->provider(true, ['wd' => 'wd-eva02-large-tagger-v3', 'ram' => 'ram-plus']), $postRepository, $client, $suggestionService);
        $handler(new GenerateSuggestionsMessage('both-id'));

        $this->assertSame($wdResult, $stored['wd']);
        $this->assertSame($ramResult, $stored['ram']);
    }

    public function test_one_model_store_failure_does_not_cost_the_other_its_results(): void
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($this->postWithPath());

        $client = $this->createStub(AutoTagInferenceClient::class);
        $client->method('analyze')->willReturn(['tags' => [['name' => 'x', 'category' => 'general', 'score' => 0.9]], 'rating' => ['label' => null, 'score' => 0.0]]);

        $sources = [];
        $suggestionService = $this->createMock(SuggestionService::class);
        $suggestionService->expects($this->exactly(2))->method('store')->willReturnCallback(
            static function (string $targetType, string $targetId, array $result, string $source) use (&$sources): void {
                $sources[] = $source;
                if ($source === 'wd') {
                    throw new \RuntimeException('db boom');
                }
            },
        );

        $handler = $this->handler($this->provider(true, ['wd' => 'wd-eva02-large-tagger-v3', 'ram' => 'ram-plus']), $postRepository, $client, $suggestionService);
        $handler(new GenerateSuggestionsMessage('both-id'));

        // The wd store threw, yet ram was still attempted — and nothing escaped to the worker.
        $this->assertSame(['wd', 'ram'], $sources);
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

        $handler = $this->handler($this->provider(true), $postRepository, $client, $suggestionService, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('video-id'));
    }

    public function test_video_frames_are_extracted_once_and_reused_by_every_model(): void
    {
        $post = new Post();
        $post->setPath('uploads/boards/1/clip.mp4')->setMimetype('video/mp4');
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('find')->willReturn($post);

        // One extraction, whatever the number of models — decoding is the expensive part.
        $thumbnailGenerator = $this->createMock(ThumbnailGenerator::class);
        $thumbnailGenerator->expects($this->once())->method('extractVideoFrames')->willReturn(['/tmp/f0.jpeg', '/tmp/f1.jpeg']);

        // 2 models × 2 frames.
        $client = $this->createMock(AutoTagInferenceClient::class);
        $client->expects($this->exactly(4))->method('analyze')->willReturn(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]);

        $handler = $this->handler($this->provider(true, ['wd' => 'wd-eva02-large-tagger-v3', 'ram' => 'ram-plus']), $postRepository, $client, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('video-id'));
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

        $handler = $this->handler($this->provider(true), $postRepository, $client, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('video-id'));
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

        $handler = $this->handler($this->provider(true), $postRepository, $client, thumbnailGenerator: $thumbnailGenerator);

        // Must not throw — extraction failure soft-fails (analyze never reached, asserted above).
        $handler(new GenerateSuggestionsMessage('video-id'));
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

        $handler = $this->handler($this->provider(true), $postRepository, $client, $suggestionService, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('video-id'));
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

        $handler = $this->handler($this->provider(true), $postRepository, $client, thumbnailGenerator: $thumbnailGenerator);
        $handler(new GenerateSuggestionsMessage('image-id'));
    }
}

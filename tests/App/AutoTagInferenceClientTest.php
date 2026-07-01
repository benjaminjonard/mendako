<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\AutoTagInferenceClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AutoTagInferenceClientTest extends TestCase
{
    private function provider(): AutoTagConfigProvider
    {
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('isEnabled')->willReturn(true);
        $provider->method('getServiceUrl')->willReturn('http://mendako_ml:8000');

        return $provider;
    }

    public function test_analyze_returns_parsed_result(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, 'imagedata');
        $body = json_encode([
            'tags' => [['name' => '1girl', 'category' => 'general', 'score' => 0.9]],
            'rating' => ['label' => 'general', 'score' => 0.8],
        ]);
        $httpClient = new MockHttpClient([new MockResponse($body, ['http_code' => 200])]);

        $result = (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))->analyze($tmp, 'wd-eva02-large-tagger-v3');
        unlink($tmp);

        $this->assertSame('1girl', $result['tags'][0]['name']);
        $this->assertSame('general', $result['rating']['label']);
    }

    public function test_analyze_sends_clip_model_and_tag_names(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, 'imagedata');
        $captured = '';
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $body = $options['body'];
            if (is_callable($body)) {
                while ('' !== ($chunk = $body(8192))) {
                    $captured .= $chunk;
                }
            } elseif (is_iterable($body)) {
                foreach ($body as $chunk) {
                    $captured .= $chunk;
                }
            }

            return new MockResponse(json_encode(['tags' => [], 'rating' => ['label' => null]]), ['http_code' => 200]);
        });

        (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))
            ->analyze($tmp, 'wd-eva02-large-tagger-v3', 'siglip2-so400m', ['cat', 'dog']);
        unlink($tmp);

        $this->assertStringContainsString('clip_model', $captured);
        $this->assertStringContainsString('tag_names', $captured);
        $this->assertStringContainsString('cat', $captured);
    }

    public function test_analyze_returns_empty_on_error(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, 'x');
        $httpClient = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('service unreachable');
        });

        $result = (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))->analyze($tmp, 'wd-eva02-large-tagger-v3');
        unlink($tmp);

        $this->assertSame([], $result);
    }

    public function test_analyze_returns_empty_on_non_2xx(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, 'x');
        $httpClient = new MockHttpClient([new MockResponse('bad image', ['http_code' => 422])]);

        $result = (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))->analyze($tmp, 'wd-eva02-large-tagger-v3');
        unlink($tmp);

        $this->assertSame([], $result);
    }

    public function test_analyze_makes_no_call_when_disabled(): void
    {
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('isEnabled')->willReturn(false);
        $provider->method('getServiceUrl')->willReturn('http://mendako_ml:8000');
        $httpClient = new MockHttpClient([new MockResponse('{}', ['http_code' => 200])]);

        $result = (new AutoTagInferenceClient($httpClient, $provider, new NullLogger()))->analyze('/nonexistent', 'wd-eva02-large-tagger-v3');

        $this->assertSame([], $result);
        $this->assertSame(0, $httpClient->getRequestsCount());
    }

    public function test_warm_vocabulary_posts_model_and_tags(): void
    {
        $captured = ['url' => '', 'body' => ''];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse('{"running":true,"done":0,"total":2}', ['http_code' => 200]);
        });

        (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))
            ->warmVocabulary('siglip2-so400m', ['cat', 'dog']);

        $this->assertSame(1, $httpClient->getRequestsCount());
        $this->assertStringEndsWith('/vocabulary', $captured['url']);
        $this->assertStringContainsString('siglip2-so400m', $captured['body']);
        $this->assertStringContainsString('cat', $captured['body']);
    }

    public function test_warm_vocabulary_no_call_when_empty_or_disabled(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{}')]);
        (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))->warmVocabulary('siglip2-so400m', []);
        $this->assertSame(0, $httpClient->getRequestsCount());
    }

    public function test_vocabulary_missing_returns_parsed(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{"cached":940,"missing":14,"total":954}', ['http_code' => 200])]);

        $coverage = (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))->vocabularyMissing('siglip2-so400m', ['cat', 'dog']);

        $this->assertSame(940, $coverage['cached']);
        $this->assertSame(14, $coverage['missing']);
        $this->assertSame(954, $coverage['total']);
    }

    public function test_vocabulary_status_returns_parsed(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{"running":true,"done":15,"total":950}', ['http_code' => 200])]);

        $status = (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))->vocabularyStatus();

        $this->assertTrue($status['running']);
        $this->assertSame(15, $status['done']);
        $this->assertSame(950, $status['total']);
    }

    public function test_vocabulary_status_empty_on_error(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('service unreachable');
        });

        $status = (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))->vocabularyStatus();

        $this->assertSame([], $status);
    }
}

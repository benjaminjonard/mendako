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

    public function test_analyze_posts_model_and_image(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, 'imagedata');
        $captured = ['url' => '', 'body' => ''];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $body = '';
            $raw = $options['body'];
            if (is_callable($raw)) {
                while ('' !== ($chunk = $raw(8192))) {
                    $body .= $chunk;
                }
            } elseif (is_iterable($raw)) {
                foreach ($raw as $chunk) {
                    $body .= $chunk;
                }
            }
            $captured = ['url' => $url, 'body' => $body];

            return new MockResponse(json_encode(['tags' => [], 'rating' => ['label' => null, 'score' => 0.0]]), ['http_code' => 200]);
        });

        (new AutoTagInferenceClient($httpClient, $this->provider(), new NullLogger()))
            ->analyze($tmp, 'wd-eva02-large-tagger-v3');
        unlink($tmp);

        $this->assertStringEndsWith('/analyze', $captured['url']);
        $this->assertStringContainsString('wd-eva02-large-tagger-v3', $captured['body']);
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

}

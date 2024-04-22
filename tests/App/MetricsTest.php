<?php

declare(strict_types=1);

namespace App\Tests\App;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class MetricsTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
    }

    public function test_can_see_metrics(): void
    {
        // Arrange
        $_ENV['APP_ENABLE_METRICS'] = 1;

        // Act
        $this->client->request('GET', '/metrics');

        // Assert
        $this->assertResponseIsSuccessful();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Service\AutoTag\AutoTagConfigProvider;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AutoTagTabsVisibilityTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    private function enableAutoTag(bool $enabled): void
    {
        $stub = $this->createStub(AutoTagConfigProvider::class);
        $stub->method('isEnabled')->willReturn($enabled);
        static::getContainer()->set(AutoTagConfigProvider::class, $stub);
    }

    public function test_tabs_are_visible_when_enabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->enableAutoTag(true);

        $this->client->request(Request::METHOD_GET, '/tags');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('/tags/blacklist', $content);
        $this->assertStringContainsString('/tag-validation', $content);
    }

    public function test_tabs_are_hidden_when_disabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->enableAutoTag(false);

        $this->client->request(Request::METHOD_GET, '/tags');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('/tags/blacklist', $content);
        $this->assertStringNotContainsString('/tag-validation', $content);
    }

    public function test_blacklist_route_accessible_when_enabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->enableAutoTag(true);

        $this->client->request(Request::METHOD_GET, '/tags/blacklist');

        $this->assertResponseIsSuccessful();
    }

    public function test_blacklist_route_404_when_disabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->enableAutoTag(false);

        $this->client->request(Request::METHOD_GET, '/tags/blacklist');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_validation_route_accessible_when_enabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->enableAutoTag(true);

        $this->client->request(Request::METHOD_GET, '/tag-validation');

        $this->assertResponseIsSuccessful();
    }

    public function test_validation_route_404_when_disabled(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
        $this->enableAutoTag(false);

        $this->client->request(Request::METHOD_GET, '/tag-validation');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}

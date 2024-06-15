<?php

declare(strict_types=1);

namespace App;

use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AdminTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
    }

    public function test_admin_can_access_administration(): void
    {
        // Arrange
        $user = UserFactory::createOne(['roles' => ['ROLE_ADMIN']])->_real();
        $this->client->loginUser($user);

        // Act
        $this->client->request('GET', '/admin');

        // Assert
        $this->assertResponseIsSuccessful();
    }

    public function test_user_cannot_access_administration(): void
    {
        // Arrange
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();
        $this->client->loginUser($user);

        // Act
        $this->client->request('GET', '/admin');

        // Assert
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}

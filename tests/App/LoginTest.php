<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class LoginTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
    }

    public function test_can_login(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();

        // Act
        $this->client->request('GET', '/');
        $this->client->submitForm('Sign in', [
            '_login' => $user->getUsername(),
            '_password' => $user->getPlainPassword()
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_board_index');
    }

    public function test_user_redirected_if_already_logged_in(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);

        // Act
        $this->client->request('GET', '/login');

        // Assert
        $this->assertRouteSame('app_board_index');
    }

    public function test_user_cant_login_with_bad_credentials(): void
    {
        // Arrange
        $user = UserFactory::createOne(['plainPassword' => 'password']);

        // Act
        $this->client->request('GET', '/');
        $crawler = $this->client->submitForm('Sign in', [
            '_login' => $user->getUsername(),
            '_password' => 'wrong password'
        ]);

        // Assert
        $this->assertSame('Sign in', $crawler->filter('h1')->text());
        $this->assertSame('Invalid credentials.', $crawler->filter('.has-text-danger')->text());
    }

    public function test_not_enabled_user_cant_login(): void
    {
        // Arrange
        $user = UserFactory::createOne(['enabled' => false]);

        // Act
        $this->client->request('GET', '/');
        $crawler = $this->client->submitForm('Sign in', [
            '_login' => $user->getUsername(),
            '_password' => $user->getPlainPassword()
        ]);

        // Assert
        $this->assertSame('Sign in', $crawler->filter('h1')->text());
        $this->assertSame('User not activated', $crawler->filter('.has-text-danger')->text());
    }
}

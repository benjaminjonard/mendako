<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ProfileTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
    }

    public function test_can_edit_profile(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);

        // Act
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Submit', [
            'user[username]' => 'Stitch',
            'user[email]' => 'stitch@koillection.com',
            'user[plainPassword][first]' => 'testtest1234',
            'user[plainPassword][second]' => 'testtest1234',
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
        UserFactory::assert()->exists(['username' => 'Stitch', 'email' => 'stitch@koillection.com']);
    }
}

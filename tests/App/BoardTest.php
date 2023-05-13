<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class BoardTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
    }

    public function test_can_get_board_list(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);
        BoardFactory::createMany(3);

        // Act
        $crawler = $this->client->request('GET', '');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_board_index');
        $this->assertCount(3, $crawler->filter('.grid-boards .card'));
    }

    public function test_can_get_board(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        PostFactory::createMany(3, ['board' => $board]);

        // Act
        $crawler = $this->client->request('GET', '/boards/'.$board->getSlug());

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_board_show', ['slug' => $board->getSlug()]);
        $this->assertCount(3, $crawler->filter('.grid-posts .image'));
    }

    public function test_can_post_board(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);

        // Act
        $this->client->request('GET', '/boards/add');
        $this->client->submitForm('Submit', [
            'board[name]' => 'Space',
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_board_show', ['slug' => 'space']);
    }

    public function test_can_edit_board(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug().'/edit');
        $this->client->submitForm('Submit', [
            'board[name]' => 'Animals',
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_board_show', ['slug' => 'animals']);
    }

    public function test_can_delete_board(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();

        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug());
        $this->client->submitForm('Agree');

        // Assert
        $this->assertRouteSame('app_board_index');
        BoardFactory::assert()->count(0);
        PostFactory::assert()->count(0);
    }
}

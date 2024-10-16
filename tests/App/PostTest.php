<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Enum\TagCategory;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PostTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
    }

    public function test_can_get_post(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/'.$post->getId());

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_post_show', ['slug' => $board->getSlug(), 'id' => $post->getId()]);
    }

    public function test_can_edit_post(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/' . $post->getId() .'/edit');
        $this->client->submitForm('Submit', [
            'post[tags]' => 'nyancat cat rainbow',
            'post[setAsBoardThumbnail]' => true,
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
    }

    public function test_post_file_is_moved_when_board_is_changed(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $newBoard = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);
        $filename = basename($post->getPath());

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/' . $post->getId() .'/edit');
        $this->client->submitForm('Submit', [
            'post[board]' => $newBoard->getId(),
        ]);

        // Assert

        $this->assertResponseIsSuccessful();
        PostFactory::assert()->exists([
            'id' => $post->getId(),
            'path' => "uploads/boards/{$newBoard->getId()}/{$filename}",
        ]);
    }

    public function test_can_delete_post(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/' . $post->getId());
        $this->client->submitForm('Agree');

        // Assert
        $this->assertRouteSame('app_board_show', ['slug' => $board->getSlug()]);
        PostFactory::assert()->count(0);
    }

    public function test_can_upload_png(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png");

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat',
            'post[setAsBoardThumbnail]' => true,
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_jpg(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.jpg', "/tmp/{$uniqId}.jpg");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.jpg", "{$uniqId}.jpg");

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_webp(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.webp', "/tmp/{$uniqId}.webp");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.webp", "{$uniqId}.webp");

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_avif(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.avif', "/tmp/{$uniqId}.avif");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.avif", "{$uniqId}.avif");

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_gif(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.gif', "/tmp/{$uniqId}.gif");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.gif", "{$uniqId}.gif");

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_mp4(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.mp4', "/tmp/{$uniqId}.mp4");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.mp4", "{$uniqId}.mp4");

        // Act
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
    }

    public function test_can_check_similar_posts(): void
    {
        // Arrange
        $user = UserFactory::createOne()->_real();
        $this->client->loginUser($user);

        $board = BoardFactory::createOne();

        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $this->client->request('GET', '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);

        // Act
        $this->client->request('POST', '/check-similar', [], ['file' => $uploadedFile]);

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, json_decode($this->client->getResponse()->getContent()));
    }
}

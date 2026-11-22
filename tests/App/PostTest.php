<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\FileTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PostTest extends WebTestCase
{
    use Factories;
    use FileTrait;
    use ResetDatabase;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
    }

    public function test_can_get_post(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $uploadedFile = $this->createFile('png');
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/'.$post->getId());

        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_post_show', ['slug' => $board->getSlug(), 'id' => $post->getId()]);
    }

    public function test_can_edit_post(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $uploadedFile = $this->createFile('png');
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/' . $post->getId() .'/edit');
        $this->client->submitForm('Submit', [
            'post[tags]' => 'nyancat cat rainbow',
            'post[setAsBoardThumbnail]' => true,
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function test_post_file_is_moved_when_board_is_changed(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $newBoard = BoardFactory::createOne();
        $uploadedFile = $this->createFile('png');
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);
        $filename = basename($post->getPath());

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/' . $post->getId() .'/edit');
        $this->client->submitForm('Submit', [
            'post[board]' => $newBoard->getId(),
        ]);

        $this->assertResponseIsSuccessful();
        PostFactory::assert()->exists([
            'id' => $post->getId(),
            'path' => "uploads/boards/{$newBoard->getId()}/{$filename}",
        ]);
    }

    public function test_can_delete_post(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $uploadedFile = $this->createFile('png');
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/' . $post->getId());
        $this->client->submitForm('Agree');

        $this->assertRouteSame('app_board_show', ['slug' => $board->getSlug()]);
        PostFactory::assert()->count(0);
    }

    public function test_can_upload_png(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $uploadedFile = $this->createFile('png');

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat',
            'post[setAsBoardThumbnail]' => true,
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function test_duplicated_new_tag_is_created_once(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $this->createFile('png'),
            'post[board]' => $board->getId(),
            'post[tags]' => 'frieren  frieren frieren',
        ]);

        $this->assertResponseIsSuccessful();
        TagFactory::assert()->count(1, ['name' => 'frieren']);
        PostFactory::assert()->count(1);
    }

    public function test_can_upload_jpg(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $uploadedFile = $this->createFile('jpg');

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_webp(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $uploadedFile = $this->createFile('webp');

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_avif(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $uploadedFile = $this->createFile('avif');

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_gif(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $uploadedFile = $this->createFile('gif');

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_mp4(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $uploadedFile = $this->createFile('mp4');

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function test_can_check_similar_posts(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);

        $board = BoardFactory::createOne();

        $uploadedFile = $this->createFile('png');
        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        $uploadedFile = $this->createFile('png');

        $this->client->request(Request::METHOD_POST, '/check-similar', [], ['file' => $uploadedFile]);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, json_decode($this->client->getResponse()->getContent()));
    }

    public function test_board_choices_are_listed_alphabetically(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        foreach (['Zebra', 'anime', 'Minerals'] as $name) {
            BoardFactory::createOne(['name' => $name]);
        }

        $crawler = $this->client->request(Request::METHOD_GET, '/boards/anime/add');

        $this->assertResponseIsSuccessful();
        $names = $crawler->filter('#post_board option')->each(static fn ($option): string => $option->text());
        $sorted = $names;
        sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
        $this->assertSame($sorted, $names);
    }
}

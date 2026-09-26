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
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PostTest extends WebTestCase
{
    use Factories;
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
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
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
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/' . $post->getId() .'/edit');
        $this->client->submitForm('Submit', [
            'post[tags]' => 'nyancat cat rainbow',
            'post[setAsBoardThumbnail]' => true,
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function test_accepting_a_suggestion_keeps_its_category(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $suggestion = (new \App\Entity\TagSuggestion())
            ->setTargetType('post')
            ->setTargetId((string) $post->getId())
            ->setTagName('explicit')
            ->setCategory(TagCategory::RATING)
            ->setScore(0.95)
            ->setSource(\App\Entity\TagSuggestion::SOURCE_WD);
        $entityManager->persist($suggestion);
        $entityManager->flush();

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug().'/'.$post->getId().'/edit');
        $this->client->submitForm('Submit', [
            'post[tags]' => 'explicit',
        ]);

        $this->assertResponseIsSuccessful();
        TagFactory::assert()->exists([
            'name' => 'explicit',
            'category' => TagCategory::RATING->value,
        ]);
    }

    public function test_post_file_is_moved_when_board_is_changed(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $newBoard = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
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
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
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
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png");

        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat',
            'post[setAsBoardThumbnail]' => true,
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function test_can_upload_jpg(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.jpg', "/tmp/{$uniqId}.jpg");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.jpg", "{$uniqId}.jpg");

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
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.webp', "/tmp/{$uniqId}.webp");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.webp", "{$uniqId}.webp");

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
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.avif', "/tmp/{$uniqId}.avif");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.avif", "{$uniqId}.avif");

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
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.gif', "/tmp/{$uniqId}.gif");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.gif", "{$uniqId}.gif");

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
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.mp4', "/tmp/{$uniqId}.mp4");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.mp4", "{$uniqId}.mp4");

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

        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug(). '/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat'
        ]);

        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);

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

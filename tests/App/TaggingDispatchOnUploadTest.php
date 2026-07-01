<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Service\AutoTag\TaggingDispatcher;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TaggingDispatchOnUploadTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
        $this->client->disableReboot();
    }

    private function uploadPng(string $slug, string $boardId): void
    {
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png");

        $this->client->request(Request::METHOD_GET, '/boards/'.$slug.'/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $uploadedFile,
            'post[board]' => $boardId,
            'post[tags]' => 'nyancat',
        ]);
    }

    public function test_upload_invokes_the_dispatcher(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $board = BoardFactory::createOne();

        $dispatcher = $this->createMock(TaggingDispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch');
        static::getContainer()->set(TaggingDispatcher::class, $dispatcher);

        $this->uploadPng($board->getSlug(), $board->getId());

        $this->assertResponseIsSuccessful();
    }

    public function test_upload_enqueues_nothing_when_ai_disabled(): void
    {
        // Default test env: MENDAKO_AUTOTAG_ENABLED unset → feature disabled.
        $this->client->loginUser(UserFactory::createOne());
        $board = BoardFactory::createOne();

        $this->uploadPng($board->getSlug(), $board->getId());

        $this->assertResponseIsSuccessful();
        $transport = static::getContainer()->get('messenger.transport.autotag_interactive');
        $this->assertCount(0, $transport->getSent());
    }
}

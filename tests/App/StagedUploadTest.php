<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\StagedUploadFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class StagedUploadTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function uploadFixture(string $fixture = 'nyancat.png'): UploadedFile
    {
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $extension = pathinfo($fixture, PATHINFO_EXTENSION);
        $filesystem->copy(__DIR__.'/../../assets/fixtures/'.$fixture, "/tmp/{$uniqId}.{$extension}");

        return new UploadedFile("/tmp/{$uniqId}.{$extension}", "{$uniqId}.{$extension}", test: true);
    }

    private function textFile(): UploadedFile
    {
        $uniqId = uniqid();
        $path = "/tmp/{$uniqId}.txt";
        file_put_contents($path, 'not an image');

        return new UploadedFile($path, "{$uniqId}.txt", test: true);
    }

    private function publicPath(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/public';
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/staging');

        return $crawler->filter('[data-bulk-upload-csrf-value]')->attr('data-bulk-upload-csrf-value');
    }

    private function stageOne(?UploadedFile $file = null): array
    {
        $this->client->request(Request::METHOD_POST, '/staging/add', ['_token' => $this->csrfToken()], ['file' => $file ?? $this->uploadFixture()]);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function test_index_requires_authentication(): void
    {
        $this->client->request(Request::METHOD_GET, '/staging');

        $this->assertResponseRedirects();
    }

    public function test_index_loads_for_authenticated_user(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request(Request::METHOD_GET, '/staging');

        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_staged_index');
    }

    public function test_index_renders_media_viewer(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $this->stageOne();

        $crawler = $this->client->request(Request::METHOD_GET, '/staging');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-bulk-upload-target="viewer"]'));
        $this->assertGreaterThan(0, $crawler->filter('.staged-card-media[data-action*="openViewer"]')->count());
    }

    public function test_can_bulk_upload_file(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $result = $this->stageOne();

        $this->assertResponseIsSuccessful();
        StagedUploadFactory::assert()->count(1);
        $staged = StagedUploadFactory::repository()->find($result['id']);
        $this->assertStringStartsWith('uploads/staging/', $staged->getPath());
        $this->assertFileExists($this->publicPath().'/'.$staged->getPath());
    }

    public function test_add_rejects_missing_csrf(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request(Request::METHOD_POST, '/staging/add', [], ['file' => $this->uploadFixture()]);

        $this->assertResponseStatusCodeSame(403);
        StagedUploadFactory::assert()->count(0);
    }

    public function test_add_rejects_missing_file(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request(Request::METHOD_POST, '/staging/add', ['_token' => $this->csrfToken()]);

        $this->assertResponseStatusCodeSame(400);
        StagedUploadFactory::assert()->count(0);
    }

    public function test_add_rejects_unsupported_mimetype(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request(Request::METHOD_POST, '/staging/add', ['_token' => $this->csrfToken()], ['file' => $this->textFile()]);

        $this->assertResponseStatusCodeSame(422);
        StagedUploadFactory::assert()->count(0);
    }

    public function test_duplicate_flag_is_persisted(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $this->client->followRedirects();

        // Create an existing Post with the same image (so its vector lands in men_post)
        $board = BoardFactory::createOne();
        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug().'/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $this->uploadFixture(),
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat',
        ]);
        $this->client->followRedirects(false);

        // Stage the same image -> flagged as a potential duplicate (stored on entity + in card)
        $result = $this->stageOne();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('staged-card-duplicate', $result['card']);
        $staged = StagedUploadFactory::repository()->find($result['id']);
        $this->assertTrue($staged->isDuplicate());
    }

    public function test_similar_endpoint_returns_matching_posts(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $this->client->followRedirects();

        $board = BoardFactory::createOne();
        $this->client->request(Request::METHOD_GET, '/boards/'.$board->getSlug().'/add');
        $this->client->submitForm('Submit', [
            'post[file]' => $this->uploadFixture(),
            'post[board]' => $board->getId(),
            'post[tags]' => 'nyancat',
        ]);
        $this->client->followRedirects(false);

        $result = $this->stageOne();

        $this->client->request(Request::METHOD_GET, '/staging/'.$result['id'].'/similar');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data['similar']);
    }

    public function test_similar_endpoint_scoped_to_user(): void
    {
        $userA = UserFactory::createOne();
        $this->client->loginUser($userA);
        $result = $this->stageOne();

        $userB = UserFactory::createOne();
        $this->client->loginUser($userB);
        $this->client->request(Request::METHOD_GET, '/staging/'.$result['id'].'/similar');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $data['similar']);
    }

    public function test_non_duplicate_is_not_flagged(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $result = $this->stageOne();

        $staged = StagedUploadFactory::repository()->find($result['id']);
        $this->assertFalse($staged->isDuplicate());
    }

    public function test_can_assign_staged_to_board(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $board = BoardFactory::createOne();

        $result = $this->stageOne();
        $staged = StagedUploadFactory::repository()->find($result['id']);
        $stagedFile = $this->publicPath().'/'.$staged->getPath();

        $this->client->request(Request::METHOD_POST, '/staging/assign', [
            '_token' => $this->csrfToken(),
            'board' => $board->getId(),
            'ids' => [$result['id']],
        ]);

        $this->assertResponseIsSuccessful();
        StagedUploadFactory::assert()->count(0);
        PostFactory::assert()->count(1);

        $post = PostFactory::repository()->findOneBy(['board' => $board->getId()]);
        $this->assertNotNull($post);
        $this->assertStringStartsWith("uploads/boards/{$board->getId()}/", $post->getPath());
        $this->assertFileExists($this->publicPath().'/'.$post->getPath());
        $this->assertFileDoesNotExist($stagedFile);
    }

    public function test_can_delete_staged(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $result = $this->stageOne();
        $staged = StagedUploadFactory::repository()->find($result['id']);
        $stagedFile = $this->publicPath().'/'.$staged->getPath();

        $this->client->request(Request::METHOD_POST, '/staging/delete', [
            '_token' => $this->csrfToken(),
            'ids' => [$result['id']],
        ]);

        $this->assertResponseIsSuccessful();
        StagedUploadFactory::assert()->count(0);
        $this->assertFileDoesNotExist($stagedFile);
    }

    public function test_bulk_assign_multiple(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $board = BoardFactory::createOne();

        $first = $this->stageOne();
        $second = $this->stageOne();
        StagedUploadFactory::assert()->count(2);

        $this->client->request(Request::METHOD_POST, '/staging/assign', [
            '_token' => $this->csrfToken(),
            'board' => $board->getId(),
            'ids' => [$first['id'], $second['id']],
        ]);

        $this->assertResponseIsSuccessful();
        StagedUploadFactory::assert()->count(0);
        PostFactory::assert()->count(2);
    }

    public function test_bulk_delete_multiple(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $first = $this->stageOne();
        $second = $this->stageOne();
        StagedUploadFactory::assert()->count(2);

        $this->client->request(Request::METHOD_POST, '/staging/delete', [
            '_token' => $this->csrfToken(),
            'ids' => [$first['id'], $second['id']],
        ]);

        $this->assertResponseIsSuccessful();
        StagedUploadFactory::assert()->count(0);
    }

    public function test_assign_rejects_invalid_csrf(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $board = BoardFactory::createOne();
        $result = $this->stageOne();

        $this->client->request(Request::METHOD_POST, '/staging/assign', [
            '_token' => 'invalid',
            'board' => $board->getId(),
            'ids' => [$result['id']],
        ]);

        $this->assertResponseStatusCodeSame(403);
        StagedUploadFactory::assert()->count(1);
    }

    public function test_cannot_delete_another_users_staged_upload(): void
    {
        // User A stages a file
        $userA = UserFactory::createOne();
        $this->client->loginUser($userA);
        $result = $this->stageOne();
        StagedUploadFactory::assert()->count(1);

        // User B tries to delete it
        $userB = UserFactory::createOne();
        $this->client->loginUser($userB);
        $this->client->request(Request::METHOD_POST, '/staging/delete', [
            '_token' => $this->csrfToken(),
            'ids' => [$result['id']],
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $data['removedIds']);
        StagedUploadFactory::assert()->count(1); // still there
    }

    public function test_index_is_scoped_to_current_user(): void
    {
        // User A stages a file
        $userA = UserFactory::createOne();
        $this->client->loginUser($userA);
        $this->stageOne();

        // User B sees none of A's staged uploads
        $userB = UserFactory::createOne();
        $this->client->loginUser($userB);
        $crawler = $this->client->request(Request::METHOD_GET, '/staging');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('.staged-card'));
    }
}

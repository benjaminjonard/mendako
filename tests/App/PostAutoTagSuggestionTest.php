<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PostAutoTagSuggestionTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
        // Keep the same kernel so container-fetched services share one entity manager.
        $this->client->disableReboot();
    }

    private function createPost(): array
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $filesystem = new Filesystem();
        $uniqId = uniqid();
        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.png', "/tmp/{$uniqId}.png");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.png", "{$uniqId}.png", test: true);
        $post = PostFactory::createOne(['board' => $board, 'file' => $uploadedFile, 'uploadedBy' => $user]);

        return [$board, $post];
    }

    public function test_store_skips_tags_already_applied_to_the_post(): void
    {
        [, $post] = $this->createPost();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // A tag already confirmed on the post must not be re-proposed on a re-run.
        $existing = TagFactory::createOne(['name' => 'existing_tag', 'category' => TagCategory::GENERAL]);
        $managedPost = $em->getRepository(\App\Entity\Post::class)->find($post->getId());
        // The transient upload file was already consumed on creation; clear it so re-flushing
        // to attach the tag doesn't retrigger the upload listener on a now-missing temp file.
        $managedPost->setFile(null);
        $managedPost->addTag($existing);
        $em->flush();

        $store = static::getContainer()->get(\App\Service\AutoTag\SuggestionService::class);
        $store->store('post', $post->getId(), ['tags' => [
            ['name' => 'existing_tag', 'score' => 0.95, 'category' => 'general'],
            ['name' => 'new_tag', 'score' => 0.90, 'category' => 'general'],
        ]]);

        $names = array_map(
            static fn (TagSuggestion $s): string => $s->getTagName(),
            static::getContainer()->get(\App\Repository\TagSuggestionRepository::class)->findForTarget('post', $post->getId()),
        );
        $this->assertContains('new_tag', $names);
        $this->assertNotContains('existing_tag', $names);
    }
}

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
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TagTest extends WebTestCase
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

    public function test_can_get_tag_list(): void
    {
        // Arrange
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        TagFactory::createMany(3);

        // Act
        $crawler = $this->client->request(Request::METHOD_GET, '/tags');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_tag_index');
        $this->assertCount(7, $crawler->filter('tbody tr')); // 7 because 4 tags are included in migrations (4 + 3)
    }

    public function test_can_edit_tag(): void
    {
        // Arrange
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $tag = TagFactory::createOne();

        // Act
        $this->client->request(Request::METHOD_GET, '/tags/'.$tag->getId().'/edit');
        $this->client->submitForm('Submit', [
            'tag[name]' => 'frieren',
            'tag[category]' => TagCategory::META->value
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_tag_index');
        TagFactory::assert()->exists([
            'id' => $tag->getId(),
            'name' => 'frieren',
            'category' => TagCategory::META->value
        ]);
    }

    public function test_can_delete_tag(): void
    {
        // Arrange
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $tag = TagFactory::createOne();

        // Act
        $this->client->request(Request::METHOD_GET, '/tags/'.$tag->getId().'/edit');
        $this->client->submitForm('Agree');

        // Assert
        $this->assertRouteSame('app_tag_index');
        TagFactory::assert()->notExists(['id' => $tag->getId()]);
    }

    public function test_can_get_tag_autocomplete(): void
    {
        // Arrange
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        TagFactory::createOne(['name' => 'dog']);
        TagFactory::createOne(['name' => 'otter']);
        TagFactory::createOne(['name' => 'capybara']);

        // Act
        $this->client->request(Request::METHOD_GET, '/tags/autocomplete?query=capy');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertJsonStringEqualsJsonString(
            json_encode([['name' => 'capybara', 'category' => TagCategory::GENERAL->value]]),
            $this->client->getResponse()->getContent()
        );
    }

    public function test_can_get_untagged_posts_list(): void
    {
        // Arrange
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $metaTag = TagFactory::createOne(['name' => 'long_video', 'category' => TagCategory::META]);
        $generalTag = TagFactory::createOne(['name' => 'nyancat', 'category' => TagCategory::GENERAL]);

        PostFactory::createOne(['board' => $board]); // no tag at all -> untagged
        PostFactory::createOne(['board' => $board, 'tags' => [$metaTag]]); // only meta tag -> untagged
        PostFactory::createOne(['board' => $board, 'tags' => [$generalTag]]); // real tag -> excluded

        // Act
        $crawler = $this->client->request(Request::METHOD_GET, '/tags/untagged');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_tag_untagged');
        $this->assertCount(2, $crawler->filter('.untagged-post')); // post with no tag + post with only a meta tag
    }

    public function test_can_add_tags_to_untagged_post(): void
    {
        // Arrange
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $board = BoardFactory::createOne();
        $post = PostFactory::createOne(['board' => $board]);

        // Act
        $crawler = $this->client->request(Request::METHOD_GET, '/tags/untagged');
        $this->assertCount(1, $crawler->filter('.untagged-post'));
        $this->client->submit($crawler->filter('.untagged-post form')->form(['tags' => 'nyancat cat']));

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_tag_untagged');
        TagFactory::assert()->exists(['name' => 'nyancat']);
        TagFactory::assert()->exists(['name' => 'cat']);
        $this->assertCount(2, PostFactory::find($post->getId())->getTags());
        // Post now has real tags, so it no longer shows up in the untagged list
        $this->assertCount(0, $this->client->getCrawler()->filter('.untagged-post'));
    }
}

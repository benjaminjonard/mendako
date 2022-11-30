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
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TagTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects();
    }

    public function test_can_get_tag_list(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);
        TagFactory::createMany(3);

        // Act
        $crawler = $this->client->request('GET', '/tags');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_tag_index');
        $this->assertCount(3, $crawler->filter('tbody tr'));
    }

    public function test_can_edit_tag(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);
        $tag = TagFactory::createOne();

        // Act
        $this->client->request('GET', '/tags/'.$tag->getId().'/edit');
        $crawler = $this->client->submitForm('Submit', [
            'tag[name]' => 'animated',
            'tag[category]' => TagCategory::META->value
        ]);

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertRouteSame('app_tag_index');
        TagFactory::assert()->exists([
            'id' => $tag->getId(),
            'name' => 'animated',
            'category' => TagCategory::META->value
        ]);
    }

    public function test_can_delete_tag(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);
        $tag = TagFactory::createOne();

        // Act
        $this->client->request('GET', '/tags/'.$tag->getId().'/edit');
        $this->client->submitForm('Agree');

        // Assert
        $this->assertRouteSame('app_tag_index');
        TagFactory::assert()->count(0);
    }

    public function test_can_get_tag_autocomplete(): void
    {
        // Arrange
        $user = UserFactory::createOne()->object();
        $this->client->loginUser($user);
        TagFactory::createOne(['name' => 'dog']);
        TagFactory::createOne(['name' => 'otter']);
        TagFactory::createOne(['name' => 'capybara']);

        // Act
        $crawler = $this->client->request('GET', '/tags/autocomplete?query=capy');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertJsonStringEqualsJsonString(
            json_encode([['name' => 'capybara', 'category' => TagCategory::GENERAL->value]]),
            $this->client->getResponse()->getContent()
        );
    }
}

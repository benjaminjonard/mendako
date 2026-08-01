<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Enum\TagCategory;
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

    public function test_tag_list_is_paginated(): void
    {
        // The 4 migration tags are META, so filtering on another category isolates the fixtures.
        $this->client->loginUser(UserFactory::createOne());
        TagFactory::createMany(25, ['category' => TagCategory::CHARACTER]);

        $crawler = $this->client->request(Request::METHOD_GET, '/tags?category=character');
        $this->assertResponseIsSuccessful();
        $this->assertCount(20, $crawler->filter('tbody tr'));
        $this->assertGreaterThan(0, $crawler->filter('nav.pagination a.pagination-link')->count());

        $crawler = $this->client->request(Request::METHOD_GET, '/tags?category=character&page=2');
        $this->assertCount(5, $crawler->filter('tbody tr'));
    }

    public function test_tag_list_can_be_searched_by_name(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        TagFactory::createOne(['name' => 'zzz_needle', 'category' => TagCategory::CHARACTER]);
        TagFactory::createMany(3, ['category' => TagCategory::CHARACTER]);

        $crawler = $this->client->request(Request::METHOD_GET, '/tags?q=zzz_needle');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('tbody tr'));
    }

    public function test_tag_list_can_be_filtered_by_category(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        TagFactory::createMany(2, ['category' => TagCategory::ARTIST]);
        TagFactory::createMany(3, ['category' => TagCategory::GENERAL]);

        $crawler = $this->client->request(Request::METHOD_GET, '/tags?category=artist');
        $this->assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('tbody tr'));
    }

    public function test_tag_list_can_be_sorted_by_name(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        TagFactory::createOne(['name' => 'aaa_first', 'category' => TagCategory::COPYRIGHT]);
        TagFactory::createOne(['name' => 'zzz_last', 'category' => TagCategory::COPYRIGHT]);

        $crawler = $this->client->request(Request::METHOD_GET, '/tags?category=copyright&sort=name&dir=ASC');
        $this->assertSame('aaa_first', trim($crawler->filter('tbody tr:first-child td:first-child')->text()));

        $crawler = $this->client->request(Request::METHOD_GET, '/tags?category=copyright&sort=name&dir=DESC');
        $this->assertSame('zzz_last', trim($crawler->filter('tbody tr:first-child td:first-child')->text()));
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

}

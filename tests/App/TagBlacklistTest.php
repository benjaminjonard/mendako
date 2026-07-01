<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\BlacklistedTag;
use App\Entity\TagSuggestion;
use App\Repository\BlacklistedTagRepository;
use App\Repository\TagSuggestionRepository;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TagBlacklistTest extends WebTestCase
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

    private function loginAdmin(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_ADMIN']]));
    }

    private function seedSuggestion(string $targetId, string $name): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new TagSuggestion())
            ->setTargetType('post')
            ->setTargetId($targetId)
            ->setTagName($name)
            ->setScore(0.9)
            ->setSource(TagSuggestion::SOURCE_WD)
            ->setStatus(TagSuggestion::STATUS_PENDING));
        $em->flush();
    }

    public function test_admin_can_blacklist_a_tag_and_name_is_normalized(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request(Request::METHOD_GET, '/tags/blacklist');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/tags/blacklist/add"]')->form();
        $form['name'] = 'Hatsune Miku';
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertNotNull(
            static::getContainer()->get(BlacklistedTagRepository::class)->findOneBy(['name' => 'hatsune_miku'])
        );
    }

    public function test_blacklisting_purges_existing_suggestions(): void
    {
        $this->loginAdmin();
        $targetId = Uuid::v7()->toRfc4122();
        $this->seedSuggestion($targetId, 'bad_tag');

        $crawler = $this->client->request(Request::METHOD_GET, '/tags/blacklist');
        $form = $crawler->filter('form[action$="/tags/blacklist/add"]')->form();
        $form['name'] = 'bad_tag';
        $this->client->submit($form);

        // The already-surfaced suggestion is gone — it must never remonter.
        $this->assertSame(
            [],
            static::getContainer()->get(TagSuggestionRepository::class)->findForTarget('post', $targetId)
        );
    }

    public function test_admin_can_remove_from_blacklist(): void
    {
        $this->loginAdmin();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new BlacklistedTag())->setName('foo'));
        $em->flush();

        $crawler = $this->client->request(Request::METHOD_GET, '/tags/blacklist');
        $form = $crawler->filter('form[action$="/delete"]')->form();
        $this->client->submit($form);

        $this->assertNull(
            static::getContainer()->get(BlacklistedTagRepository::class)->findOneBy(['name' => 'foo'])
        );
    }

    public function test_non_admin_cannot_add_to_blacklist(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER']]));

        $this->client->request(Request::METHOD_POST, '/tags/blacklist/add', ['name' => 'x']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_blacklist_page_forbidden_for_non_admin(): void
    {
        $this->client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER']]));

        $this->client->request(Request::METHOD_GET, '/tags/blacklist');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}

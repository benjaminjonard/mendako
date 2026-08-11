<?php

namespace App\Tests\Factory;

use App\Entity\User;
use App\Enum\Theme;
use App\Repository\UserRepository;
use Zenstruck\Foundry\ModelFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;
use Zenstruck\Foundry\Proxy;
use Zenstruck\Foundry\RepositoryProxy;

/**
 * @extends \Zenstruck\Foundry\Persistence\PersistentObjectFactory<\App\Entity\User>
 */
final class UserFactory extends \Zenstruck\Foundry\Persistence\PersistentObjectFactory
{
    private static int $sequence = 0;

    #[\Override]
    protected function defaults(): array
    {
        // Faker's unique() memory is lost when the kernel reboots between requests, so build unique values ourselves
        $unique = ++self::$sequence.bin2hex(random_bytes(4));

        return [
            'username' => 'user_'.$unique,
            'email' => 'user_'.$unique.'@example.com',
            'plainPassword' => self::faker()->password(),
            'enabled' => true,
            'roles' => ['ROLE_USER'],
            'timezone' => self::faker()->timezone(),
            'theme' => Theme::BROWSER->value,
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }

    #[\Override]
    public static function class(): string
    {
        return User::class;
    }
}

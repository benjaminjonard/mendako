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
    #[\Override]
    protected function defaults(): array
    {
        return [
            'username' => self::faker()->unique()->word(),
            'email' => self::faker()->unique()->email(),
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

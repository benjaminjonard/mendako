<?php

namespace App\Tests\Factory;

use App\Entity\User;
use App\Enum\Theme;
use App\Repository\UserRepository;
use Zenstruck\Foundry\ModelFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;
use Zenstruck\Foundry\Proxy;
use Zenstruck\Foundry\RepositoryProxy;

final class UserFactory extends PersistentProxyObjectFactory
{
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

    protected function initialize(): static
    {
        return $this;
    }

    public static function class(): string
    {
        return User::class;
    }
}

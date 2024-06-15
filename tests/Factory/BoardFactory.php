<?php

namespace App\Tests\Factory;

use App\Entity\Board;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

final class BoardFactory extends PersistentProxyObjectFactory
{
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->word(),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }

    public static function class(): string
    {
        return Board::class;
    }
}

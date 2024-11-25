<?php

namespace App\Tests\Factory;

use App\Entity\Board;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

final class BoardFactory extends PersistentProxyObjectFactory
{
    #[\Override]
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->word(),
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
        return Board::class;
    }
}

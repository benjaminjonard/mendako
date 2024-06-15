<?php

namespace App\Tests\Factory;

use App\Entity\Tag;
use App\Enum\TagCategory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

final class TagFactory extends PersistentProxyObjectFactory
{
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->word(),
            'category' => TagCategory::GENERAL,
            'suggested' => false,
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }

    public static function class(): string
    {
        return Tag::class;
    }
}

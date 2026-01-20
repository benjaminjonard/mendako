<?php

namespace App\Tests\Factory;

use App\Entity\Tag;
use App\Enum\TagCategory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends \Zenstruck\Foundry\Persistence\PersistentObjectFactory<\App\Entity\Tag>
 */
final class TagFactory extends \Zenstruck\Foundry\Persistence\PersistentObjectFactory
{
    #[\Override]
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->word(),
            'category' => TagCategory::GENERAL,
            'suggested' => false,
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
        return Tag::class;
    }
}

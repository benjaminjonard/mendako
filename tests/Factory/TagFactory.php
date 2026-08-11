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
    private static int $sequence = 0;

    #[\Override]
    protected function defaults(): array
    {
        // Faker's unique() memory is lost when the kernel reboots between requests, so build unique values ourselves
        return [
            'name' => 'tag_'.++self::$sequence.bin2hex(random_bytes(4)),
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

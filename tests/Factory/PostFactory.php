<?php

namespace App\Tests\Factory;

use App\Entity\Post;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends \Zenstruck\Foundry\Persistence\PersistentObjectFactory<\App\Entity\Post>
 */
final class PostFactory extends \Zenstruck\Foundry\Persistence\PersistentObjectFactory
{
    #[\Override]
    protected function defaults(): array
    {
        return [
            'mimetype' => 'image/jpeg',
            'height' => self::faker()->randomNumber(3),
            'width' => self::faker()->randomNumber(3),
            'size' => self::faker()->randomNumber(6),
            'seenCounter' => self::faker()->randomNumber(),
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
        return Post::class;
    }
}

<?php

namespace App\Tests\Factory;

use App\Entity\Post;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

final class PostFactory extends PersistentProxyObjectFactory
{
    protected function defaults(): array
    {
        return [
            'mimetype' => self::faker()->mimeType(),
            'height' => self::faker()->randomNumber(3),
            'width' => self::faker()->randomNumber(3),
            'size' => self::faker()->randomNumber(6),
            'seenCounter' => self::faker()->randomNumber(),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }

    public static function class(): string
    {
        return Post::class;
    }
}

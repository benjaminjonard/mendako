<?php

namespace App\Tests\Factory;

use App\Entity\StagedUpload;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<StagedUpload>
 */
final class StagedUploadFactory extends PersistentObjectFactory
{
    #[\Override]
    protected function defaults(): array
    {
        return [
            'mimetype' => self::faker()->mimeType(),
            'height' => self::faker()->randomNumber(3),
            'width' => self::faker()->randomNumber(3),
            'size' => self::faker()->randomNumber(6),
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
        return StagedUpload::class;
    }
}

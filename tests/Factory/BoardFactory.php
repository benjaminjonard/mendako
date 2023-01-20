<?php

namespace App\Tests\Factory;

use App\Entity\Board;
use App\Repository\BoardRepository;
use Zenstruck\Foundry\ModelFactory;
use Zenstruck\Foundry\Proxy;
use Zenstruck\Foundry\RepositoryProxy;

/**
 * @extends ModelFactory<Board>
 *
 * @method static Board|Proxy createOne(array $attributes = [])
 * @method static Board[]|Proxy[] createMany(int $number, array|callable $attributes = [])
 * @method static Board[]|Proxy[] createSequence(array|callable $sequence)
 * @method static Board|Proxy find(object|array|mixed $criteria)
 * @method static Board|Proxy findOrCreate(array $attributes)
 * @method static Board|Proxy first(string $sortedField = 'id')
 * @method static Board|Proxy last(string $sortedField = 'id')
 * @method static Board|Proxy random(array $attributes = [])
 * @method static Board|Proxy randomOrCreate(array $attributes = [])
 * @method static Board[]|Proxy[] all()
 * @method static Board[]|Proxy[] findBy(array $attributes)
 * @method static Board[]|Proxy[] randomSet(int $number, array $attributes = [])
 * @method static Board[]|Proxy[] randomRange(int $min, int $max, array $attributes = [])
 * @method static BoardRepository|RepositoryProxy repository()
 * @method Board|Proxy create(array|callable $attributes = [])
 */
final class BoardFactory extends ModelFactory
{
    protected function getDefaults(): array
    {
        return [
            'name' => self::faker()->unique()->word(),
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
        ];
    }

    protected function initialize(): self
    {
        return $this;
    }

    protected static function getClass(): string
    {
        return Board::class;
    }
}

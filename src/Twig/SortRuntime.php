<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\RuntimeExtensionInterface;

class SortRuntime implements RuntimeExtensionInterface
{
    public function naturalSorting(iterable $array): array
    {
        $array = !\is_array($array) ? $array->toArray() : $array;

        $collator = collator_create('root');
        $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);

        usort($array, function ($a, $b) use ($collator): bool|int {
            return $collator->compare($a['name'], $b['name']);
        });

        return $array;
    }
}
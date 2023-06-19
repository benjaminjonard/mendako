<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class SortExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('naturalSorting', static function (iterable $array) : array {
                return (new SortRuntime())->naturalSorting($array);
            })
        ];
    }
}
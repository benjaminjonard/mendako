<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('bytes', [AppRuntime::class, 'bytes']),
            new TwigFilter('minutes', [AppRuntime::class, 'minutes']),
            new TwigFilter('ago', [AppRuntime::class, 'ago']),
        ];
    }
}
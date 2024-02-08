<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class IntlExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('getLocales', [IntlRuntime::class, 'getLocales']),
            new TwigFunction('getLocaleLabel', [IntlRuntime::class, 'getLocaleLabel']),
            new TwigFunction('getCountryFlag', [IntlRuntime::class, 'getCountryFlag']),
        ];
    }
}
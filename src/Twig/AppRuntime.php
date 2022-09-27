<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\RuntimeExtensionInterface;

class AppRuntime implements RuntimeExtensionInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function bytes(float $bytes, int $precision = 2): string
    {
        $base = $bytes > 0 ? log($bytes, 1024) : $bytes;

        $suffixes = ['', 'Ki', 'Mi', 'Gi', 'Ti', 'Pi', 'Ei', 'Zi', 'Yi'];

        return round(pow(1024, $base - floor($base)), $precision).' '.$suffixes[floor($base)].$this->translator->trans('global.byte_abbreviation');
    }
}

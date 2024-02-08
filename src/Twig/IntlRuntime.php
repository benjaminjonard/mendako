<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\LocaleHelper;
use Twig\Extension\RuntimeExtensionInterface;

class IntlRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly LocaleHelper $localeHelper
    ) {
    }

    public function getLocales(): array
    {
        return $this->localeHelper->getLocaleLabels();
    }

    public function getLocaleLabel(string $code): string
    {
        $this->localeHelper->getLocaleLabels();

        return $this->localeHelper->getLocaleLabels()[$code] ?? $this->localeHelper->getLocaleLabels()[$this->localeHelper->getDefaultLocale()];
    }

    public function getCountryFlag(string $code): string
    {
        return $this->localeHelper->getEmojiFlag($code);
    }
}

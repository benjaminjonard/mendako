<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\LocaleHelper;
use Twig\Attribute\AsTwigFunction;

class IntlExtension
{
    public function __construct(
        private readonly LocaleHelper $localeHelper
    ) {
    }
    
    #[AsTwigFunction('getLocales')]
    public function getLocales(): array
    {
        return $this->localeHelper->getLocaleLabels();
    }

    #[AsTwigFunction('getLocaleLabel')]
    public function getLocaleLabel(string $code): string
    {
        $this->localeHelper->getLocaleLabels();

        return $this->localeHelper->getLocaleLabels()[$code] ?? $this->localeHelper->getLocaleLabels()[$this->localeHelper->getDefaultLocale()];
    }
    
    #[AsTwigFunction('getCountryFlag')]
    public function getCountryFlag(string $code): string
    {
        return $this->localeHelper->getEmojiFlag($code);
    }
}

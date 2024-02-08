<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Languages;

readonly class LocaleHelper
{
    public function __construct(
        #[Autowire('%default_locale%')] private string $defaultLocale,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales
    ) {
    }

    public function getLocaleLabels(): array
    {
        $languages = [];

        foreach ($this->enabledLocales as $locale) {
            $languages[$locale] = ucfirst(Languages::getName($locale, $locale));
        }

        return $languages;
    }

    public function getLocaleLabelsWithFlag(): array
    {
        $languages = [];

        foreach ($this->enabledLocales as $locale) {
            $languages[$locale] = $this->getEmojiFlag($locale) . ' ' . ucfirst(Languages::getName($locale, $locale));
        }

        return $languages;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function getEmojiFlag(string $countryCode): string
    {
        $countryCode = mb_strtoupper($countryCode);
        if ($countryCode === 'EN') {
            $countryCode = 'US';
        }

        if (\strlen($countryCode) > 2) {
            $countryCode = substr($countryCode, -2);
        }

        $regionalOffset = 0x1F1A5;

        return mb_chr($regionalOffset + mb_ord($countryCode[0], 'UTF-8'), 'UTF-8')
            . mb_chr($regionalOffset + mb_ord($countryCode[1], 'UTF-8'), 'UTF-8');
    }
}
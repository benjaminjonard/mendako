<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\AutoTag\AutoTagConfigProvider;
use Twig\Attribute\AsTwigFunction;
use Twig\Extension\RuntimeExtensionInterface;

class AutoTagRuntime implements RuntimeExtensionInterface
{
    public function __construct(private readonly AutoTagConfigProvider $autoTagConfigProvider)
    {
    }

    #[AsTwigFunction('autotag_enabled')]
    public function autoTagEnabled(): bool
    {
        return $this->autoTagConfigProvider->isEnabled();
    }
}

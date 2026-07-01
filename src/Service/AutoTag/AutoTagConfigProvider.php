<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Single source of truth for the automatic tagging feature's effective state and configuration.
 *
 * Everything is configured by environment variables (like the rest of the app config):
 *   - MENDAKO_AUTOTAG_ENABLED — master on/off switch (off by default, so the feature ships off).
 *   - MENDAKO_ML_URL          — the inference service URL.
 * Every automatic tagging entry point must gate on isEnabled().
 */
class AutoTagConfigProvider
{
    private const string DEFAULT_SERVICE_URL = 'http://mendako_ml:8000';

    public function __construct(
        // `default::` so an instance that never sets these env vars still boots (off / built-in URL).
        #[Autowire('%env(bool:default::MENDAKO_AUTOTAG_ENABLED)%')] private readonly bool $enabled = false,
        #[Autowire('%env(default::MENDAKO_ML_URL)%')] private readonly string $serviceUrl = '',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getServiceUrl(): string
    {
        return $this->serviceUrl !== '' ? $this->serviceUrl : self::DEFAULT_SERVICE_URL;
    }

    /**
     * The active model id for a category ('wd' | 'clip'), or null for an unknown category.
     *
     * Each category has exactly one model, baked into the service image, so there is no
     * selection: the model is taken straight from the static catalog.
     */
    public function getActiveModel(string $category): ?string
    {
        return ModelCatalog::modelsFor($category)[0] ?? null;
    }
}

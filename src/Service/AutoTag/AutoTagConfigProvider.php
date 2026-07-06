<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Single source of truth for the auto-tagging feature's state and configuration, all driven by
 * environment variables:
 *   - APP_AUTOTAG_ENABLED               — master on/off switch (off by default).
 *   - APP_ML_URL                        — the inference service URL.
 *   - APP_AUTOTAG_AUTOVALIDATE_THRESHOLD — min WD confidence (percent) to auto-apply a tag.
 *   - APP_AUTOTAG_BOARDS                — comma-separated board slugs to tag (empty = none, * = all).
 * Every auto-tagging entry point must gate on isEnabled().
 */
class AutoTagConfigProvider
{
    private const string DEFAULT_SERVICE_URL = 'http://mendako_ml:8000';

    // WD suggestions at/above this confidence are auto-applied to the post (accepted without review);
    // below it they wait as pending suggestions. Expressed as a percent so the admin config reads
    // naturally.
    private const float DEFAULT_AUTOVALIDATE_THRESHOLD_PERCENT = 85.0;

    public function __construct(
        // `default::` so an instance that never sets these env vars still boots (off / built-in URL).
        #[Autowire('%env(bool:default::APP_AUTOTAG_ENABLED)%')] private readonly bool $enabled = false,
        #[Autowire('%env(default::APP_ML_URL)%')] private readonly string $serviceUrl = '',
        #[Autowire('%env(default::APP_AUTOTAG_AUTOVALIDATE_THRESHOLD)%')] private readonly string $autoValidateThreshold = '',
        #[Autowire('%env(default::APP_AUTOTAG_BOARDS)%')] private readonly string $boards = '',
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
     * The auto-validation confidence as a percent (0–100), for display in the admin config.
     * Clamped so a typo can't silently disable (negative) or over-trigger (>100) auto-validation.
     */
    public function getAutoValidateThresholdPercent(): float
    {
        $value = $this->autoValidateThreshold !== ''
            ? (float) $this->autoValidateThreshold
            : self::DEFAULT_AUTOVALIDATE_THRESHOLD_PERCENT;

        return max(0.0, min(100.0, $value));
    }

    /**
     * Same threshold as a 0–1 score fraction, ready to compare against a suggestion's score.
     */
    public function getAutoValidateThreshold(): float
    {
        return $this->getAutoValidateThresholdPercent() / 100.0;
    }

    public function getEnabledBoardSlugs(): array
    {
        return array_values(array_filter(
            array_map(static fn (string $slug): string => mb_strtolower(trim($slug)), explode(',', $this->boards)),
            static fn (string $slug): bool => $slug !== '',
        ));
    }

    public function isBoardEnabled(?string $slug): bool
    {
        $allowed = $this->getEnabledBoardSlugs();

        if ($allowed === []) {
            return false;
        }

        if (in_array('*', $allowed, true)) {
            return true;
        }

        return $slug !== null && in_array(mb_strtolower($slug), $allowed, true);
    }

    /**
     * The active model id for a category ('wd'), or null for an unknown category. Each category has
     * exactly one model baked into the service image, taken straight from the static catalog.
     */
    public function getActiveModel(string $category): ?string
    {
        return ModelCatalog::modelsFor($category)[0] ?? null;
    }
}

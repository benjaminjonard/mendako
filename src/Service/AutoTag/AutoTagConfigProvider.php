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
 *   - APP_AUTOTAG_BOARDS_WITH_WD        — board slugs tagged by the WD illustration tagger.
 *   - APP_AUTOTAG_BOARDS_WITH_RAM       — board slugs tagged by the RAM++ photo tagger.
 * Both board lists are comma-separated (empty = none, * = all); a board named in both is tagged
 * by both models. Every auto-tagging entry point must gate on isEnabled().
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
        // Nullable: an empty board list is the normal "tag nothing with this model" state, and the
        // `default::` processor turns an empty env var into null.
        #[Autowire('%env(default::APP_AUTOTAG_BOARDS_WITH_WD)%')] private readonly ?string $wdBoards = '',
        #[Autowire('%env(default::APP_AUTOTAG_BOARDS_WITH_RAM)%')] private readonly ?string $ramBoards = '',
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

    /**
     * Board slugs tagged by the WD illustration tagger.
     */
    public function getWdBoardSlugs(): array
    {
        return $this->parseBoardSlugs($this->wdBoards);
    }

    /**
     * Board slugs tagged by the RAM++ photo tagger.
     */
    public function getRamBoardSlugs(): array
    {
        return $this->parseBoardSlugs($this->ramBoards);
    }

    /**
     * Every board slug tagged by at least one model — the scope of the tagging job, and what the
     * admin coverage counts run against. A slug listed for both models appears once.
     */
    public function getEnabledBoardSlugs(): array
    {
        return array_values(array_unique([...$this->getWdBoardSlugs(), ...$this->getRamBoardSlugs()]));
    }

    public function isBoardEnabled(?string $slug): bool
    {
        return $this->getModelsForBoard($slug) !== [];
    }

    /**
     * The models to run on a board, as `category => model id` (e.g. `['wd' => 'wd-eva02-large-tagger-v3']`).
     * The category doubles as the suggestion source, so a caller can store each result without a lookup.
     * Empty when the board is configured for no model — the caller must then skip it entirely.
     */
    public function getModelsForBoard(?string $slug): array
    {
        $models = [];

        foreach (['wd' => $this->getWdBoardSlugs(), 'ram' => $this->getRamBoardSlugs()] as $category => $allowed) {
            if (!$this->matchesBoard($allowed, $slug)) {
                continue;
            }

            $model = $this->getActiveModel($category);
            if ($model !== null) {
                $models[$category] = $model;
            }
        }

        return $models;
    }

    /**
     * The active model id for a category ('wd', 'ram'), or null for an unknown category. Each category
     * has exactly one model baked into the service image, taken straight from the static catalog.
     */
    public function getActiveModel(string $category): ?string
    {
        return ModelCatalog::modelsFor($category)[0] ?? null;
    }

    private function parseBoardSlugs(?string $raw): array
    {
        return array_values(array_filter(
            array_map(static fn (string $slug): string => mb_strtolower(trim($slug)), explode(',', $raw ?? '')),
            static fn (string $slug): bool => $slug !== '',
        ));
    }

    private function matchesBoard(array $allowed, ?string $slug): bool
    {
        if ($allowed === []) {
            return false;
        }

        if (in_array('*', $allowed, true)) {
            return true;
        }

        return $slug !== null && in_array(mb_strtolower($slug), $allowed, true);
    }
}

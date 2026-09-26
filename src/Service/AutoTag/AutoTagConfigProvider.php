<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AutoTagConfigProvider
{
    private const string DEFAULT_SERVICE_URL = 'http://mendako_ml:8000';

    private const float DEFAULT_AUTOVALIDATE_THRESHOLD_PERCENT = 85.0;

    public function __construct(
        #[Autowire('%env(bool:default::APP_AUTOTAG_ENABLED)%')] private readonly bool $enabled = false,
        #[Autowire('%env(default::APP_ML_URL)%')] private readonly string $serviceUrl = '',
        #[Autowire('%env(default::APP_AUTOTAG_AUTOVALIDATE_THRESHOLD_WD)%')] private readonly ?string $wdAutoValidateThreshold = '',
        #[Autowire('%env(default::APP_AUTOTAG_BOARDS_WITH_WD)%')] private readonly ?string $wdBoards = '',
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

    public function getAutoValidateThresholdPercent(string $source): float
    {
        $raw = $this->rawThresholdFor($source);
        $value = ($raw !== null && $raw !== '')
            ? (float) $raw
            : self::DEFAULT_AUTOVALIDATE_THRESHOLD_PERCENT;

        return max(0.0, min(100.0, $value));
    }

    public function getAutoValidateThreshold(string $source): float
    {
        return $this->getAutoValidateThresholdPercent($source) / 100.0;
    }

    public function getWdBoardSlugs(): array
    {
        return $this->parseBoardSlugs($this->wdBoards);
    }

    public function getEnabledBoardSlugs(): array
    {
        return $this->getWdBoardSlugs();
    }

    public function isBoardEnabled(?string $slug): bool
    {
        return $this->getModelsForBoard($slug) !== [];
    }

    public function getModelsForBoard(?string $slug): array
    {
        $models = [];

        foreach (['wd' => $this->getWdBoardSlugs()] as $category => $allowed) {
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

    public function getActiveModel(string $category): ?string
    {
        return ModelCatalog::modelsFor($category)[0] ?? null;
    }

    private function rawThresholdFor(string $source): ?string
    {
        return match ($source) {
            'wd' => $this->wdAutoValidateThreshold,
            default => null,
        };
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

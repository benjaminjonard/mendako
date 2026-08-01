<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\TagSuggestion;

/**
 * Splits a target's pending suggestions into the ones confident enough to pre-fill the tag field
 * and the ones offered as click-to-add chips, deduped by name across sources.
 *
 * Confidence is judged against the producing model's own threshold.
 */
class SuggestionSplitter
{
    public function __construct(
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
    ) {
    }

    /**
     * @param TagSuggestion[] $suggestions
     *
     * @return array{0: array<int, array>, 1: array<int, array>} confident prefills, then chips
     */
    public function split(array $suggestions): array
    {
        $pending = array_filter(
            $suggestions,
            static fn (TagSuggestion $suggestion): bool => $suggestion->getStatus() === TagSuggestion::STATUS_PENDING,
        );

        $confident = [];
        $chips = [];
        $seen = [];

        foreach ($pending as $suggestion) {
            $name = $suggestion->getTagName();
            if (isset($seen[$name])) {
                continue;
            }
            if ($suggestion->getScore() >= $this->autoTagConfigProvider->getAutoValidateThreshold($suggestion->getSource())) {
                $confident[] = $this->entry($suggestion);
                $seen[$name] = true;
            }
        }

        foreach ($pending as $suggestion) {
            $name = $suggestion->getTagName();
            if (!isset($seen[$name])) {
                $chips[] = $this->entry($suggestion);
                $seen[$name] = true;
            }
        }

        return [$confident, $chips];
    }

    private function entry(TagSuggestion $suggestion): array
    {
        return [
            'name' => $suggestion->getTagName(),
            'category' => $suggestion->getCategory()?->value ?? 'general',
            'score' => $suggestion->getScore(),
            'source' => $suggestion->getSource(),
        ];
    }
}

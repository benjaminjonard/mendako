<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use App\Repository\BlacklistedTagRepository;
use App\Repository\TagSuggestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\UnicodeString;

/**
 * Persists the service /analyze result as non-authoritative TagSuggestions.
 *
 * Write separation (FR17/FR18): this service writes ONLY men_tag_suggestion. It
 * never touches men_post_tag — confirmed tags are written solely by the human
 * tag-save flow. Re-running for a target upserts that source's pending rows and
 * never removes accepted/dismissed ones, so nothing the user touched is lost.
 */
class SuggestionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagSuggestionRepository $tagSuggestionRepository,
        private readonly BlacklistedTagRepository $blacklistedTagRepository,
    ) {
    }

    /**
     * @param array{tags?: list<array{name?: string, category?: string, score?: float|int}>, rating?: array{label?: string|null, score?: float|int}} $analyzeResult
     */
    public function store(string $targetType, string $targetId, array $analyzeResult, string $source = TagSuggestion::SOURCE_WD): void
    {
        // Names the user has blacklisted for the AI: these must never surface as a suggestion,
        // whatever the source or score, so drop them before they ever become candidates.
        $blacklist = array_flip($this->blacklistedTagRepository->allNames());

        // name => [score, category]; collapse duplicates to the highest score.
        $candidates = [];

        foreach ($analyzeResult['tags'] ?? [] as $tag) {
            $name = $this->normalizeName($tag['name'] ?? null);
            if ($name === null || isset($blacklist[$name])) {
                continue;
            }
            $score = (float) ($tag['score'] ?? 0.0);
            $category = $this->mapCategory($tag['category'] ?? null);
            if (!isset($candidates[$name]) || $score > $candidates[$name]['score']) {
                $candidates[$name] = ['score' => $score, 'category' => $category];
            }
        }

        $ratingLabel = $analyzeResult['rating']['label'] ?? null;
        if ($ratingLabel !== null) {
            $name = $this->normalizeName($ratingLabel);
            if ($name !== null && !isset($blacklist[$name])) {
                $score = (float) ($analyzeResult['rating']['score'] ?? 0.0);
                // A rating is always categorized as RATING and wins over a same-named general tag.
                if (!isset($candidates[$name]) || $score > $candidates[$name]['score']) {
                    $candidates[$name] = ['score' => $score, 'category' => TagCategory::RATING];
                }
            }
        }

        // Persist ranked by score (highest first).
        uasort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        // Upsert atomically: drop this source's stale pending rows, then re-insert, skipping names
        // the user already decided on (accepted/dismissed) — across ALL sources, so a decision made
        // on a wd suggestion also silences the same name coming from knn, and vice versa.
        $this->entityManager->wrapInTransaction(function () use ($targetType, $targetId, $source, $candidates): void {
            $this->tagSuggestionRepository->deletePendingForTarget($targetType, $targetId, $source);
            $known = array_flip($this->tagSuggestionRepository->decidedTagNamesForTarget($targetType, $targetId));

            foreach ($candidates as $name => $candidate) {
                if (isset($known[$name])) {
                    continue;
                }
                $suggestion = (new TagSuggestion())
                    ->setTargetType($targetType)
                    ->setTargetId($targetId)
                    ->setTagName($name)
                    ->setCategory($candidate['category'])
                    ->setScore($candidate['score'])
                    ->setSource($source);
                $this->entityManager->persist($suggestion);
            }
        });
    }

    private function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        // Match Tag::setName so suggestions line up with real tags.
        $normalized = (new UnicodeString($name))->lower()->replace(' ', '_')->toString();

        if ($normalized === '') {
            return null;
        }

        // Guard against a pathological/untrusted service name overflowing the
        // VARCHAR(255) column (would otherwise throw a DBAL write error). A WD tag
        // is always short, so anything this long is junk — skip it.
        return mb_strlen($normalized) > 255 ? null : $normalized;
    }

    private function mapCategory(?string $category): ?TagCategory
    {
        if ($category === null) {
            return null;
        }

        return TagCategory::tryFrom($category);
    }
}

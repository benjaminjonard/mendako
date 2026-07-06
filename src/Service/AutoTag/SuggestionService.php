<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\Tag;
use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use App\Repository\BlacklistedTagRepository;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use App\Repository\TagSuggestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\UnicodeString;

/**
 * Persists the service /analyze result as TagSuggestions. A suggestion on a real post whose score
 * clears APP_AUTOTAG_AUTOVALIDATE_THRESHOLD is auto-validated: applied to the post (men_post_tag)
 * and stored as ACCEPTED instead of pending. Re-running upserts the source's pending rows and never
 * removes accepted/dismissed ones.
 */
class SuggestionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagSuggestionRepository $tagSuggestionRepository,
        private readonly BlacklistedTagRepository $blacklistedTagRepository,
        private readonly PostRepository $postRepository,
        private readonly TagRepository $tagRepository,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
    ) {
    }

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

        // Names the target already carries as confirmed tags (men_post_tag). Re-proposing a tag the
        // post already has is noise, so treat it like a decided name and skip it. Only a Post has
        // applied tags — a bulk/StagedPost has none yet. Normalized to line up with candidate names.
        $applied = [];
        if ($targetType === 'post') {
            foreach ($this->postRepository->appliedTagNamesForPost($targetId) as $appliedName) {
                $normalized = $this->normalizeName($appliedName);
                if ($normalized !== null) {
                    $applied[$normalized] = true;
                }
            }
        }

        // Upsert atomically: drop this source's stale pending rows, then re-insert, skipping names
        // the user already decided on (accepted/dismissed) — across ALL sources, so a decision made
        // on a wd suggestion also silences the same name coming from knn, and vice versa — as well
        // as names already applied to the post.
        $autoValidate = $targetType === 'post';
        $threshold = $this->autoTagConfigProvider->getAutoValidateThreshold();
        $tagSource = $source === TagSuggestion::SOURCE_WD ? Tag::SOURCE_WD : Tag::SOURCE_CUSTOM;

        $this->entityManager->wrapInTransaction(function () use ($targetType, $targetId, $source, $candidates, $applied, $autoValidate, $threshold, $tagSource): void {
            $this->tagSuggestionRepository->deletePendingForTarget($targetType, $targetId, $source);

            // Names the WD model emits are known to it: flip any matching `custom` tag to `wd`.
            // (array keys are cast to int by PHP; men_tag.name is a string column.)
            if ($source === TagSuggestion::SOURCE_WD) {
                $this->tagRepository->reclassifyToWd(array_map('strval', array_keys($candidates)));
            }

            $post = null;

            $known = array_flip($this->tagSuggestionRepository->decidedTagNamesForTarget($targetType, $targetId)) + $applied;

            foreach ($candidates as $name => $candidate) {
                if (isset($known[$name])) {
                    continue;
                }

                $accepted = false;
                if ($autoValidate && $candidate['score'] >= $threshold) {
                    $post ??= $this->postRepository->find($targetId);
                    if ($post !== null) {
                        $post->addTag($this->resolveTag((string) $name, $candidate['category'], $tagSource));
                        $accepted = true;
                    }
                }

                $suggestion = (new TagSuggestion())
                    ->setTargetType($targetType)
                    ->setTargetId($targetId)
                    ->setTagName((string) $name)
                    ->setCategory($candidate['category'])
                    ->setScore($candidate['score'])
                    ->setSource($source)
                    ->setStatus($accepted ? TagSuggestion::STATUS_ACCEPTED : TagSuggestion::STATUS_PENDING);
                $this->entityManager->persist($suggestion);
            }
        });
    }

    private function resolveTag(string $name, ?TagCategory $category, string $source): Tag
    {
        $tag = $this->tagRepository->findOneBy(['name' => $name]);
        if ($tag !== null) {
            return $tag;
        }

        $tag = (new Tag())
            ->setName($name)
            ->setCategory($category ?? TagCategory::GENERAL)
            ->setSource($source);
        $this->entityManager->persist($tag);

        return $tag;
    }

    private function normalizeName(int|string|null $name): ?string
    {
        if ($name === null) {
            return null;
        }

        // A purely numeric tag name (e.g. "2023") is decoded from the service JSON as an int
        // — and PHP also silently casts numeric array keys to int downstream — so coerce to
        // string before normalizing.
        $name = (string) $name;

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

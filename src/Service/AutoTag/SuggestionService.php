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
        $blacklist = array_flip($this->blacklistedTagRepository->allNames());

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
                if (!isset($candidates[$name]) || $score > $candidates[$name]['score']) {
                    $candidates[$name] = ['score' => $score, 'category' => TagCategory::RATING];
                }
            }
        }

        uasort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $applied = [];
        if ($targetType === 'post') {
            foreach ($this->postRepository->appliedTagNamesForPost($targetId) as $appliedName) {
                $normalized = $this->normalizeName($appliedName);
                if ($normalized !== null) {
                    $applied[$normalized] = true;
                }
            }
        }

        $autoValidate = $targetType === 'post';
        $threshold = $this->autoTagConfigProvider->getAutoValidateThreshold($source);
        $tagSource = $source === TagSuggestion::SOURCE_WD ? $source : Tag::SOURCE_CUSTOM;

        $this->entityManager->wrapInTransaction(function () use ($targetType, $targetId, $source, $candidates, $applied, $autoValidate, $threshold, $tagSource): void {
            $this->tagSuggestionRepository->deletePendingForTarget($targetType, $targetId, $source);

            if ($tagSource !== Tag::SOURCE_CUSTOM) {
                $this->tagRepository->reclassifyToModel(array_map('strval', array_keys($candidates)), $tagSource);
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

        $name = (string) $name;

        $normalized = (new UnicodeString($name))->lower()->replace(' ', '_')->toString();

        if ($normalized === '') {
            return null;
        }

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

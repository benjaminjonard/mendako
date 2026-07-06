<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use App\Entity\Tag;
use App\Enum\TagCategory;
use App\Repository\TagRepository;
use App\Repository\TagSuggestionRepository;
use Symfony\Component\Form\DataTransformerInterface;

class StringToTagTransformer implements DataTransformerInterface
{
    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly TagSuggestionRepository $tagSuggestionRepository,
    ) {
    }

    #[\Override]
    public function transform($tags): string
    {
        $results = [];
        foreach ($tags as $tag) {
            $results[] = $tag->getName();
        }

        return implode(' ', $results);
    }

    #[\Override]
    public function reverseTransform($string): array
    {
        if (empty($string)) {
            return [];
        }

        $parts = explode(' ', $string);
        $tags = [];
        foreach ($parts as $part) {
            $name = trim($part);

            if ('' === $name) {
                continue;
            }

            $tag = $this->tagRepository->findOneBy(['name' => $name]);

            if ($tag === null) {
                // A brand-new tag born from an accepted suggestion keeps the suggested
                // category (rating/character/…); anything typed by hand falls back to general.
                $category = $this->tagSuggestionRepository->findCategoryForName($name) ?? TagCategory::GENERAL;

                $source = $this->tagSuggestionRepository->isKnownByWd($name) ? Tag::SOURCE_WD : Tag::SOURCE_CUSTOM;

                $tag = new Tag();
                $tag
                    ->setName($name)
                    ->setCategory($category)
                    ->setSource($source)
                ;
            }

            if (!\in_array($tag, $tags, false)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}

<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use App\Entity\Tag;
use App\Enum\TagCategory;
use App\Repository\TagRepository;
use Symfony\Component\Form\DataTransformerInterface;

class StringToTagTransformer implements DataTransformerInterface
{
    public function __construct(
        private readonly TagRepository $tagRepository
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

            if ('' === $name || isset($tags[$name])) {
                continue;
            }

            $tags[$name] = $this->tagRepository->findOneBy(['name' => $name]) ?? new Tag()->setName($name)->setCategory(TagCategory::GENERAL);
        }

        return array_values($tags);
    }
}

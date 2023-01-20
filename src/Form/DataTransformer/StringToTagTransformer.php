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

    public function transform($tags): string
    {
        $results = [];
        foreach ($tags as $tag) {
            $results[] = $tag->getName();
        }

        return implode(' ', $results);
    }

    public function reverseTransform($string): array
    {
        if (empty($string)) {
            return [];
        }

        $parts = explode(' ', $string);
        $tags = [];
        foreach ($parts as $part) {
            $name = trim($part);

            if ('' == $name) {
                continue;
            }

            $tag = $this->tagRepository->findOneBy(['name' => $name]);

            if ($tag === null) {
                $tag = new Tag();
                $tag
                    ->setName($name)
                    ->setCategory(TagCategory::GENERAL)
                ;
            }

            if (!\in_array($tag, $tags, false)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}

<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\PostRepository;
use Twig\Attribute\AsTwigFunction;
use Twig\Extension\RuntimeExtensionInterface;

class TagValidationRuntime implements RuntimeExtensionInterface
{
    public function __construct(private readonly PostRepository $postRepository)
    {
    }

    #[AsTwigFunction('pending_validation_count')]
    public function pendingValidationCount(): int
    {
        return $this->postRepository->countPostsWithPendingSuggestions();
    }
}

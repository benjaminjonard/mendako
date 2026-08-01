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

    /**
     * Number of posts still waiting in the Tag validation queue — drives the badge next to the
     * Validation tab. A lazy runtime so the COUNT only runs on pages that actually render the tab.
     */
    #[AsTwigFunction('pending_validation_count')]
    public function pendingValidationCount(): int
    {
        return $this->postRepository->countPostsWithPendingSuggestions();
    }
}

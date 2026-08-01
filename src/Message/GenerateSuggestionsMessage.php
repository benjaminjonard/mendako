<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asynchronous request to generate tag suggestions for a post. Carries the post id only; the
 * models to run are resolved from the post's board when the handler picks the message up, so a
 * configuration change applies to messages already queued. The handler is idempotent, so a re-run
 * simply re-processes the same post.
 */
final readonly class GenerateSuggestionsMessage
{
    public function __construct(
        public string $id,
    ) {
    }
}

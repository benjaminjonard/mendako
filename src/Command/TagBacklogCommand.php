<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\BacklogEnqueuer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retroactively enqueue automatic tagging tag-suggestion generation for existing posts (or, with
 * --staged, staged uploads) on the deprioritized `autotag_batch` queue. By default only
 * items that have no suggestion yet are enqueued; `--all` re-enqueues everything.
 *
 * Feature-gated, additive, idempotent: the same `GenerateSuggestionsHandler` runs,
 * suggestions are never auto-applied and confirmed tags are never touched.
 */
#[AsCommand(
    name: 'app:autotag:tag-backlog',
    description: 'Enqueue retroactive automatic tagging for existing posts (or staged uploads) on the autotag_batch queue',
)]
class TagBacklogCommand extends Command
{
    public function __construct(
        private readonly BacklogEnqueuer $backlogEnqueuer,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Re-enqueue every item, not just those without suggestions');
        $this->addOption('staged', null, InputOption::VALUE_NONE, 'Tag the staging area instead of posts');
        $this->setHelp(<<<'HELP'
            Enqueues retroactive automatic tagging on the deprioritized <info>autotag_batch</info> queue.

            By default only <comment>posts</comment> that have <comment>never been automatic tagging-processed</comment> (no suggestion of
            any status) are enqueued — an item whose suggestions were all accepted or
            dismissed is left alone. Pass <info>--staged</info> to tag the staging area instead.
            Use <info>--all</info> to re-process every item (e.g. after switching models); this
            re-runs inference on the whole set, so it is costly.

            Re-running before the queue drains will re-enqueue the same items (duplicate
            work); the handler is idempotent so results stay correct.
            HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->autoTagConfigProvider->isEnabled()) {
            $io->warning('automatic tagging is disabled — nothing was enqueued. Enable the feature and re-run.');

            return Command::SUCCESS;
        }

        $all = (bool) $input->getOption('all');
        $staged = (bool) $input->getOption('staged');

        $count = $this->backlogEnqueuer->enqueue($staged ? 'staged' : 'post', $all);

        $io->success(sprintf('Enqueued %d %s for retroactive tagging on autotag_batch.', $count, $staged ? 'staged upload(s)' : 'post(s)'));

        return Command::SUCCESS;
    }
}

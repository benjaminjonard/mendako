<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AutoTag\SuggestedTagPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tag:delete-suggested',
    description: 'Delete every tag auto-tagging applied to a post and purge the suggestion history',
)]
class DeleteSuggestedTagsCommand extends Command
{
    public function __construct(private readonly SuggestedTagPurger $suggestedTagPurger)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without persisting any change')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt, required to run non-interactively')
        ;

        $this->setHelp(<<<'HELP'
            Reverts automatic tagging, in three steps:

              1. every tag an <comment>accepted</comment> suggestion put on a post is detached from it,
              2. tags then left on no post at all are deleted,
              3. the whole suggestion history is purged, emptying <info>Tags -> Validation</info>.

            A tag you typed yourself is kept even when a model also emits its name: the tag's
            <comment>source</comment> is not the criterion, an accepted suggestion is.

            Use <info>--dry-run</info> to see the volume first. The deletion cannot be undone, so it
            asks for confirmation; pass <info>--force</info> to skip the prompt (required under
            <info>--no-interaction</info>).
            HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $preview = $this->suggestedTagPurger->preview();

        $io->table(
            ['What', 'Rows'],
            [
                ['Tags to detach from a post', $preview['links']],
                ['Tags left on no post, to delete', $preview['tags']],
                ['Suggestions to purge', $preview['suggestions']],
            ],
        );

        if ($preview['links'] === 0 && $preview['suggestions'] === 0) {
            $io->success('No suggested tag to delete.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->success($this->summary($preview, true));

            return Command::SUCCESS;
        }

        if (!$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Refusing to delete without a confirmation, pass --force to run non-interactively.');

                return Command::FAILURE;
            }

            if (!$io->confirm('This cannot be undone. Delete them?', false)) {
                $io->warning('Aborted, nothing was deleted.');

                return Command::SUCCESS;
            }
        }

        $io->success($this->summary($this->suggestedTagPurger->purge(), false));

        return Command::SUCCESS;
    }

    /**
     * @param array{links: int, tags: int, suggestions: int} $counts
     */
    private function summary(array $counts, bool $dryRun): string
    {
        return sprintf(
            $dryRun
                ? 'Would detach %d tag(s) from their post, delete %d unused tag(s) and purge %d suggestion(s).'
                : 'Detached %d tag(s) from their post, deleted %d unused tag(s), purged %d suggestion(s).',
            $counts['links'],
            $counts['tags'],
            $counts['suggestions'],
        );
    }
}

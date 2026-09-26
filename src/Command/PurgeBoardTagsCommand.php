<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\TagCategory;
use App\Service\AutoTag\BoardTagPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tag:purge-board',
    description: 'Remove every tag from one board\'s posts, except the ones named as kept',
)]
class PurgeBoardTagsCommand extends Command
{
    public function __construct(private readonly BoardTagPurger $boardTagPurger)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('board', InputArgument::REQUIRED, 'Board slug')
            ->addOption('keep', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tag name to keep; repeat for several')
            ->addOption('keep-file', null, InputOption::VALUE_REQUIRED, 'File of tag names to keep, one per line, # starts a comment')
            ->addOption('keep-category', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tag category to keep whole, e.g. artist; repeat for several')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without persisting any change')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt, required to run non-interactively')
        ;

        $this->setHelp(<<<'HELP'
            Empties one board of its tags, in three steps:

              1. every tag its posts carry is detached from them,
              2. tags then left on no post at all are deleted,
              3. those posts' suggestion history is purged, so a re-run can propose them again.

            Unlike <info>app:tag:delete-suggested</info> this does not care how a tag arrived: a name
            you typed by hand goes too. That is what makes it the tool for re-tagging a board from
            scratch after a model change, and what makes <comment>--keep</comment> worth using.

            Step 3 is not optional. Automatic tagging skips any name you have already accepted or
            dismissed, so leaving the history would silence exactly the tags a re-run should restore.

                <info>app:tag:purge-board anime --dry-run</info>
                <info>app:tag:purge-board anime --keep=favourite --keep=to_sort</info>
                <info>app:tag:purge-board anime --keep-category=artist --keep-category=copyright</info>
                <info>app:tag:purge-board anime --keep-file=var/keep.txt --force</info>

            Categories are <info>general</info>, <info>character</info>, <info>copyright</info>,
            <info>artist</info>, <info>meta</info> and <info>rating</info>.

            Other boards are never touched, and a tag another board still carries survives.
            The deletion cannot be undone, so it asks for confirmation; pass <info>--force</info> to
            skip the prompt (required under <info>--no-interaction</info>).
            HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $board = (string) $input->getArgument('board');

        try {
            $keep = $this->keptNames($input);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $categories = $this->keptCategories($input, $io);
        if ($categories === null) {
            return Command::FAILURE;
        }

        $preview = $this->boardTagPurger->preview($board, $keep, $categories);
        if ($preview['posts'] === 0) {
            $io->error(sprintf('No board with slug "%s", or it holds no post.', $board));

            return Command::FAILURE;
        }

        if ($keep !== []) {
            $io->text(sprintf('Keeping %d name(s): %s', count($keep), implode(', ', $keep)));
        }
        if ($categories !== []) {
            $io->text(sprintf('Keeping every tag in: %s', implode(', ', $categories)));
        }
        $io->table(
            ['What', 'Rows'],
            [
                ['Posts on the board', $preview['posts']],
                ['Tags to detach from a post', $preview['links']],
                ['Tags left on no post, to delete', $preview['tags']],
                ['Suggestions to purge', $preview['suggestions']],
            ],
        );

        if ($preview['links'] === 0 && $preview['suggestions'] === 0) {
            $io->success('Nothing to delete on this board.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->success($this->summary($preview, true));

            return Command::SUCCESS;
        }

        if (!$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Refusing to delete without a confirmation, pass --force to run non-interactively.');

                return Command::FAILURE;
            }

            if (!$io->confirm(sprintf('This cannot be undone. Empty "%s"?', $board), false)) {
                $io->warning('Aborted, nothing was deleted.');

                return Command::SUCCESS;
            }
        }

        $io->success($this->summary($this->boardTagPurger->purge($board, $keep, $categories), false));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function keptCategories(InputInterface $input, SymfonyStyle $io): ?array
    {
        /** @var list<string> $asked */
        $asked = $input->getOption('keep-category');
        $known = array_map(static fn (TagCategory $c): string => $c->value, TagCategory::cases());

        $unknown = array_diff($asked, $known);
        if ($unknown !== []) {
            $io->error(sprintf(
                'Unknown categor%s: %s. Known: %s.',
                count($unknown) > 1 ? 'ies' : 'y',
                implode(', ', $unknown),
                implode(', ', $known),
            ));

            return null;
        }

        return array_values(array_unique($asked));
    }

    /**
     * @return list<string>
     */
    private function keptNames(InputInterface $input): array
    {
        /** @var list<string> $names */
        $names = $input->getOption('keep');
        $path = $input->getOption('keep-file');

        if (is_string($path) && $path !== '') {
            if (!is_readable($path)) {
                throw new \RuntimeException(sprintf('Cannot read the keep file "%s".', $path));
            }

            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $name = trim(explode('#', $line, 2)[0]);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array{posts: int, links: int, tags: int, suggestions: int} $counts
     */
    private function summary(array $counts, bool $dryRun): string
    {
        return sprintf(
            $dryRun
                ? 'Would detach %d tag(s) from their post, delete %d unused tag(s) and purge %d suggestion(s) over %d post(s).'
                : 'Detached %d tag(s) from their post, deleted %d unused tag(s), purged %d suggestion(s) over %d post(s).',
            $counts['links'],
            $counts['tags'],
            $counts['suggestions'],
            $counts['posts'],
        );
    }
}

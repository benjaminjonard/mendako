<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PostRepository;
use App\Repository\BulkUploadRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only backlog progress: how many posts / bulk uploads have been automatic tagging-processed
 * (have at least one TagSuggestion) vs the total, with a completion state. Derived from
 * per-item tagging status — not from queue internals.
 */
#[AsCommand(
    name: 'app:autotag:batch-status',
    description: 'Show retroactive automatic tagging progress (processed/total) for posts and bulk uploads',
)]
class AutoTagBatchStatusCommand extends Command
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly BulkUploadRepository $bulkUploadRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = [];
        $allComplete = true;
        foreach (['Posts' => $this->postRepository, 'Bulk uploads' => $this->bulkUploadRepository] as $label => $repository) {
            $total = $repository->countAll();
            $remaining = $repository->countWithoutSuggestions();
            $processed = $total - $remaining;
            $complete = $remaining === 0;
            $allComplete = $allComplete && $complete;

            $percent = $total === 0 ? 100 : (int) round($processed / $total * 100);
            $rows[] = [$label, sprintf('%d / %d', $processed, $total), $percent.'%', $complete ? 'complete' : 'in progress'];
        }

        $io->table(['Target', 'Processed', 'Progress', 'State'], $rows);
        $io->writeln($allComplete ? '<info>Backlog complete.</info>' : '<comment>Backlog in progress.</comment>');

        return Command::SUCCESS;
    }
}

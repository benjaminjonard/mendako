<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Post;
use App\Entity\StagedUpload;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\TaggingDispatcher;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Maintenance operation for changing the active CLIP model / embedding dimension.
 *
 * Per table: drop the hnsw index → purge clip_vector + clip_model_id → ALTER the
 * column to the new dimension → recreate the index → re-dispatch the pipeline so
 * embeddings are recomputed by the worker. No tag loss (tags and the 271-dim
 * duplicate-detection vector are untouched); embeddings are recomputable.
 *
 * NOTE: if the new dimension differs from the entity mapping (1152), update the
 * `clipVector` column's `dimensions` option on Post/StagedUpload to match.
 */
#[AsCommand(
    name: 'app:autotag:reindex-clip-embeddings',
    description: 'Purge + re-dimension + re-embed CLIP embeddings (run after switching to a different-dimension CLIP model)',
)]
class ReindexClipEmbeddingsCommand extends Command
{
    private const array TABLES = ['men_post', 'men_staged_upload'];

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly TaggingDispatcher $taggingDispatcher,
        private readonly AutoTagConfigProvider $autoTagConfigProvider,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('dimension', InputArgument::REQUIRED, 'The new embedding dimension (e.g. 1152 for SigLIP2 SO400M)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dimension = (int) $input->getArgument('dimension');
        if ($dimension <= 0) {
            $io->error('Dimension must be a positive integer.');

            return Command::INVALID;
        }

        $connection = $this->managerRegistry->getConnection();

        foreach (self::TABLES as $table) {
            $index = sprintf('idx_%s_clip_vector_hnsw', $table);
            // One transaction per table so a mid-way failure can't leave the table
            // with a dropped index + purged embeddings (DDL is transactional in Postgres).
            $connection->transactional(function ($connection) use ($table, $index, $dimension): void {
                $connection->executeStatement(sprintf('DROP INDEX IF EXISTS %s', $index));
                // Purge: embeddings are recomputable; tags + the 271-dim vector are left intact.
                $connection->executeStatement(sprintf('UPDATE %s SET clip_vector = NULL, clip_model_id = NULL', $table));
                $connection->executeStatement(sprintf('ALTER TABLE %s ALTER COLUMN clip_vector TYPE vector(%d)', $table, $dimension));
                $connection->executeStatement(sprintf('CREATE INDEX %s ON %s USING hnsw (clip_vector vector_cosine_ops)', $index, $table));
            });
            $io->writeln(sprintf('  <info>%s</info>: purged, re-dimensioned to vector(%d), index rebuilt', $table, $dimension));
        }

        if ($dimension !== 1152) {
            $io->warning(sprintf('Update the clipVector `dimensions` mapping option to %d on Post and StagedUpload so the ORM matches the new column.', $dimension));
        }

        if (!$this->autoTagConfigProvider->isEnabled()) {
            $io->warning('automatic tagging feature is disabled — embeddings purged and re-dimensioned, but NOT recomputed. Enable the feature and re-run to re-embed.');

            return Command::SUCCESS;
        }

        // Bulk re-embed is backlog work: route it to the deprioritized autotag_batch queue
        // so it never competes with interactive uploads.
        $dispatched = 0;
        foreach ([Post::class, StagedUpload::class] as $entityClass) {
            foreach ($this->managerRegistry->getRepository($entityClass)->findAll() as $item) {
                $this->taggingDispatcher->dispatchBatch($item);
                ++$dispatched;
            }
        }

        $io->success(sprintf('Re-dispatched %d item(s) for re-embedding.', $dispatched));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Post;
use App\Entity\BulkUpload;
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
 * Maintenance operation for changing the active embedding encoder / dimension.
 *
 * Drop the hnsw index → truncate men_embedding → ALTER the column to the new dimension →
 * recreate the index → re-dispatch the pipeline so embeddings are recomputed by the worker.
 * No tag loss (tags and the 271-dim duplicate-detection vector are untouched); embeddings are
 * recomputable.
 *
 * NOTE: if the new dimension differs from the entity mapping (1024), update the
 * `embeddingVector` column's `dimensions` option on the Embedding entity to match.
 */
#[AsCommand(
    name: 'app:autotag:reindex-embeddings',
    description: 'Purge + re-dimension + re-embed the embedding pool (run after switching to a different-dimension encoder)',
)]
class ReindexEmbeddingsCommand extends Command
{
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

        // One transaction so a mid-way failure can't leave a dropped index + purged embeddings
        // (DDL is transactional in Postgres).
        $connection->transactional(function ($connection) use ($dimension): void {
            $connection->executeStatement('DROP INDEX IF EXISTS idx_men_embedding_vector_hnsw');
            // Purge: embeddings are recomputable; tags + the 271-dim vector are left intact.
            $connection->executeStatement('TRUNCATE TABLE men_embedding');
            $connection->executeStatement(sprintf('ALTER TABLE men_embedding ALTER COLUMN embedding_vector TYPE vector(%d)', $dimension));
            $connection->executeStatement('CREATE INDEX idx_men_embedding_vector_hnsw ON men_embedding USING hnsw (embedding_vector vector_cosine_ops)');
        });
        $io->writeln(sprintf('  <info>men_embedding</info>: purged, re-dimensioned to vector(%d), index rebuilt', $dimension));

        if ($dimension !== 1024) {
            $io->warning(sprintf('Update the embeddingVector `dimensions` mapping option to %d on the Embedding entity so the ORM matches the new column.', $dimension));
        }

        if (!$this->autoTagConfigProvider->isEnabled()) {
            $io->warning('automatic tagging feature is disabled — embeddings purged and re-dimensioned, but NOT recomputed. Enable the feature and re-run to re-embed.');

            return Command::SUCCESS;
        }

        // Bulk re-embed is backlog work: route it to the deprioritized autotag_batch queue
        // so it never competes with interactive uploads.
        $dispatched = 0;
        foreach ([Post::class, BulkUpload::class] as $entityClass) {
            foreach ($this->managerRegistry->getRepository($entityClass)->findAll() as $item) {
                $this->taggingDispatcher->dispatchBatch($item);
                ++$dispatched;
            }
        }

        $io->success(sprintf('Re-dispatched %d item(s) for re-embedding.', $dispatched));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Switch the perceptual duplicate-detection vector from the old 271-dim mixed
 * (dHash + colour histogram + dominant colours, L2-normalised) blend to a 64-dim binary DCT pHash.
 *
 * The old vectors are incompatible (different dimension + meaning) and recomputable, so purge them;
 * tags, embeddings and everything else are untouched. Existing posts carry a NULL vector until the
 * admin "Duplicate vectors" backfill job (or `app:regenerate-vectors`) recomputes them.
 */
final class Version20260701200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-dimension duplicate-detection vector 271 -> 64 (mixed L2 blend -> binary DCT pHash); purge old vectors';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        // Only men_post carries an HNSW index on the duplicate-detection vector (staged uploads are
        // only ever the query side, never searched), so just that one is dropped/rebuilt.
        $this->addSql('DROP INDEX IF EXISTS idx_post_vector_hnsw');
        foreach (['men_post', 'men_staged_upload'] as $table) {
            // Can't cast 271-d -> 64-d, and the values are meaningless under the new algorithm, so null first.
            $this->addSql(sprintf('UPDATE %s SET vector = NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN vector TYPE vector(64)', $table));
        }
        $this->addSql('CREATE INDEX idx_post_vector_hnsw ON men_post USING hnsw (vector vector_l2_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('DROP INDEX IF EXISTS idx_post_vector_hnsw');
        foreach (['men_post', 'men_staged_upload'] as $table) {
            $this->addSql(sprintf('UPDATE %s SET vector = NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN vector TYPE vector(271)', $table));
        }
        $this->addSql('CREATE INDEX idx_post_vector_hnsw ON men_post USING hnsw (vector vector_l2_ops)');
    }
}

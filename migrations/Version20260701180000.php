<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move embeddings to a dedicated men_embedding table (N vectors per item: one per video frame)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE TABLE men_embedding (
            id CHAR(36) NOT NULL,
            target_type VARCHAR(16) NOT NULL,
            target_id CHAR(36) NOT NULL,
            ordinal SMALLINT DEFAULT 0 NOT NULL,
            embedding_model_id VARCHAR(255) NOT NULL,
            embedding_vector vector(1024) NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_embedding_target ON men_embedding (target_type, target_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_embedding_target_ordinal ON men_embedding (target_type, target_id, ordinal)');
        $this->addSql('CREATE INDEX idx_men_embedding_vector_hnsw ON men_embedding USING hnsw (embedding_vector vector_cosine_ops)');

        // Embeddings now live in men_embedding; drop the per-row columns (recomputable, no loss).
        foreach (['men_post', 'men_staged_upload'] as $table) {
            $this->addSql(sprintf('DROP INDEX IF EXISTS idx_%s_embedding_vector_hnsw', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN embedding_vector', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN embedding_model_id', $table));
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        foreach (['men_post', 'men_staged_upload'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD embedding_vector vector(1024) DEFAULT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD embedding_model_id VARCHAR(255) DEFAULT NULL', $table));
            $this->addSql(sprintf('CREATE INDEX idx_%s_embedding_vector_hnsw ON %s USING hnsw (embedding_vector vector_cosine_ops)', $table, $table));
        }

        $this->addSql('DROP TABLE men_embedding');
    }
}

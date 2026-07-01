<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-dimension embedding_vector 1152 -> 1024 (switch embedding encoder to WD fc_norm); purge old embeddings';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        foreach (['men_post', 'men_staged_upload'] as $table) {
            $index = sprintf('idx_%s_embedding_vector_hnsw', $table);
            $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $index));
            // Old SigLIP (1152-d) embeddings are incompatible with the WD (1024-d) encoder and
            // recomputable, so purge them; tags and the 271-d duplicate-detection vector are untouched.
            $this->addSql(sprintf('UPDATE %s SET embedding_vector = NULL, embedding_model_id = NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN embedding_vector TYPE vector(1024)', $table));
            $this->addSql(sprintf('CREATE INDEX %s ON %s USING hnsw (embedding_vector vector_cosine_ops)', $index, $table));
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        foreach (['men_post', 'men_staged_upload'] as $table) {
            $index = sprintf('idx_%s_embedding_vector_hnsw', $table);
            $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $index));
            $this->addSql(sprintf('UPDATE %s SET embedding_vector = NULL, embedding_model_id = NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN embedding_vector TYPE vector(1152)', $table));
            $this->addSql(sprintf('CREATE INDEX %s ON %s USING hnsw (embedding_vector vector_cosine_ops)', $index, $table));
        }
    }
}

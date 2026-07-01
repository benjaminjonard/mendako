<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename clip_vector/clip_model_id -> embedding_vector/embedding_model_id (encoder-agnostic embedding pool)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        foreach (['men_post', 'men_staged_upload'] as $table) {
            $this->addSql(sprintf('ALTER INDEX IF EXISTS idx_%s_clip_vector_hnsw RENAME TO idx_%s_embedding_vector_hnsw', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN clip_vector TO embedding_vector', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN clip_model_id TO embedding_model_id', $table));
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        foreach (['men_post', 'men_staged_upload'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN embedding_vector TO clip_vector', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN embedding_model_id TO clip_model_id', $table));
            $this->addSql(sprintf('ALTER INDEX IF EXISTS idx_%s_embedding_vector_hnsw RENAME TO idx_%s_clip_vector_hnsw', $table, $table));
        }
    }
}

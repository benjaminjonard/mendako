<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add clip_vector(1152) + clip_model_id (semantic CLIP embedding) to men_post and men_staged_upload';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        foreach (['men_post', 'men_staged_upload'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD clip_vector vector(1152) DEFAULT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD clip_model_id VARCHAR(255) DEFAULT NULL', $table));
            // Unit-normalized embeddings → cosine distance is the natural metric (used by 3.2 kNN).
            $this->addSql(sprintf(
                'CREATE INDEX idx_%s_clip_vector_hnsw ON %s USING hnsw (clip_vector vector_cosine_ops)',
                $table,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        foreach (['men_post', 'men_staged_upload'] as $table) {
            $this->addSql(sprintf('DROP INDEX IF EXISTS idx_%s_clip_vector_hnsw', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN clip_vector', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN clip_model_id', $table));
        }
    }
}

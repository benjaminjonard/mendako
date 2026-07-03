<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] Auto-tagging & bulk upload schema (men_staged_post, men_tag_suggestion, men_embedding, men_blacklisted_tag)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        // Bulk uploads (un-sorted staging area). Only ever the query side of duplicate search,
        // never searched, so it carries no HNSW index on its vector.
        $this->addSql('
            CREATE TABLE men_staged_post (
                id CHAR(36) NOT NULL,
                uploaded_by_id CHAR(36) DEFAULT NULL,
                path VARCHAR(255) DEFAULT NULL,
                mimetype VARCHAR(255) NOT NULL,
                height INT DEFAULT NULL,
                width INT DEFAULT NULL,
                size INT NOT NULL,
                duration INT DEFAULT NULL,
                has_sound BOOLEAN DEFAULT false NOT NULL,
                vector vector(64) DEFAULT NULL,
                is_duplicate BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX uniq_men_staged_post_path ON men_staged_post (path)');
        $this->addSql('CREATE INDEX idx_men_staged_post_uploaded_by ON men_staged_post (uploaded_by_id)');
        $this->addSql('ALTER TABLE men_staged_post ADD CONSTRAINT fk_men_staged_post_uploaded_by FOREIGN KEY (uploaded_by_id) REFERENCES men_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Non-authoritative automatic tag suggestions (polymorphic target: post | bulk).
        $this->addSql('
            CREATE TABLE men_tag_suggestion (
                id CHAR(36) NOT NULL,
                target_type VARCHAR(16) NOT NULL,
                target_id CHAR(36) NOT NULL,
                tag_name VARCHAR(255) NOT NULL,
                category VARCHAR(255) DEFAULT NULL,
                score DOUBLE PRECISION NOT NULL,
                source VARCHAR(16) NOT NULL,
                status VARCHAR(16) DEFAULT \'pending\' NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        ');
        $this->addSql('CREATE INDEX idx_tag_suggestion_target ON men_tag_suggestion (target_type, target_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tag_suggestion_target_source_name ON men_tag_suggestion (target_type, target_id, source, tag_name)');

        // Semantic embeddings, encoder-agnostic (1024-d, WD fc_norm). N vectors per item: one per video frame.
        // Unit-normalized embeddings -> cosine distance is the natural metric.
        $this->addSql('
            CREATE TABLE men_embedding (
                id CHAR(36) NOT NULL,
                target_type VARCHAR(16) NOT NULL,
                target_id CHAR(36) NOT NULL,
                ordinal SMALLINT DEFAULT 0 NOT NULL,
                embedding_model_id VARCHAR(255) NOT NULL,
                embedding_vector vector(1024) NOT NULL,
                PRIMARY KEY(id)
            )
        ');
        $this->addSql('CREATE INDEX idx_embedding_target ON men_embedding (target_type, target_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_embedding_target_ordinal ON men_embedding (target_type, target_id, ordinal)');
        $this->addSql('CREATE INDEX idx_men_embedding_vector_hnsw ON men_embedding USING hnsw (embedding_vector vector_cosine_ops)');

        // Tag names the automatic tagging must never suggest.
        $this->addSql('
            CREATE TABLE men_blacklisted_tag (
                id CHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX uniq_blacklisted_tag_name ON men_blacklisted_tag (name)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260703092721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] Add auto tagging and staged post tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE men_blacklisted_tag (id CHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_18EEA37F5E237E06 ON men_blacklisted_tag (name)');
        $this->addSql('CREATE TABLE men_staged_post (id CHAR(36) NOT NULL, path VARCHAR(255) DEFAULT NULL, mimetype VARCHAR(255) NOT NULL, height INT DEFAULT NULL, width INT DEFAULT NULL, size INT NOT NULL, duration INT DEFAULT NULL, has_sound BOOLEAN DEFAULT false NOT NULL, vector vector(64) DEFAULT NULL, is_duplicate BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, uploaded_by_id CHAR(36) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_59552E0FB548B0F ON men_staged_post (path)');
        $this->addSql('CREATE INDEX IDX_59552E0FA2B28FE8 ON men_staged_post (uploaded_by_id)');
        $this->addSql('CREATE TABLE men_tag_suggestion (id CHAR(36) NOT NULL, target_type VARCHAR(16) NOT NULL, target_id CHAR(36) NOT NULL, tag_name VARCHAR(255) NOT NULL, category VARCHAR(255) DEFAULT NULL, score DOUBLE PRECISION NOT NULL, source VARCHAR(16) NOT NULL, status VARCHAR(16) DEFAULT \'pending\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_tag_suggestion_target ON men_tag_suggestion (target_type, target_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tag_suggestion_target_source_name ON men_tag_suggestion (target_type, target_id, source, tag_name)');
        $this->addSql('ALTER TABLE men_staged_post ADD CONSTRAINT FK_59552E0FA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES men_user (id)');
        $this->addSql('COMMENT ON COLUMN men_board.created_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN men_board.updated_at IS \'\'');
        $this->addSql('DROP INDEX idx_post_vector_hnsw');
        $this->addSql('UPDATE men_post SET vector = NULL');
        $this->addSql('ALTER TABLE men_post ALTER vector TYPE vector(64)');
        $this->addSql('COMMENT ON COLUMN men_post.created_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN men_post.updated_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN men_tag.created_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN men_tag.updated_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN men_user.roles IS \'\'');
        $this->addSql('COMMENT ON COLUMN men_user.created_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN men_user.updated_at IS \'\'');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

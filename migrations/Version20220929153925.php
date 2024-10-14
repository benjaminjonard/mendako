<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20220929153925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] First init.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE TABLE men_board (id CHAR(36) NOT NULL, thumbnail_id CHAR(36) DEFAULT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B0B8D425E237E06 ON men_board (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B0B8D42989D9B62 ON men_board (slug)');
        $this->addSql('CREATE INDEX IDX_6B0B8D42FDFF2E92 ON men_board (thumbnail_id)');
        $this->addSql('COMMENT ON COLUMN men_board.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN men_board.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE men_post (id CHAR(36) NOT NULL, board_id CHAR(36) DEFAULT NULL, uploaded_by_id CHAR(36) DEFAULT NULL, path VARCHAR(255) DEFAULT NULL, mimetype VARCHAR(255) NOT NULL, height INT NOT NULL, width INT NOT NULL, size INT NOT NULL, duration INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4318CE0B548B0F ON men_post (path)');
        $this->addSql('CREATE INDEX IDX_4318CE0E7EC5785 ON men_post (board_id)');
        $this->addSql('CREATE INDEX IDX_4318CE0A2B28FE8 ON men_post (uploaded_by_id)');
        $this->addSql('COMMENT ON COLUMN men_post.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN men_post.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE men_post_tag (post_id CHAR(36) NOT NULL, tag_id CHAR(36) NOT NULL, PRIMARY KEY(post_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_B182CE0E4B89032C ON men_post_tag (post_id)');
        $this->addSql('CREATE INDEX IDX_B182CE0EBAD26311 ON men_post_tag (tag_id)');
        $this->addSql('CREATE TABLE men_tag (id CHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, category VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6690D4FB5E237E06 ON men_tag (name)');
        $this->addSql('COMMENT ON COLUMN men_tag.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN men_tag.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE men_user (id CHAR(36) NOT NULL, username VARCHAR(32) NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL, roles TEXT NOT NULL, timezone VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D3283624F85E0677 ON men_user (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D3283624E7927C74 ON men_user (email)');
        $this->addSql('COMMENT ON COLUMN men_user.roles IS \'(DC2Type:simple_array)\'');
        $this->addSql('COMMENT ON COLUMN men_user.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN men_user.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE men_board ADD CONSTRAINT FK_6B0B8D42FDFF2E92 FOREIGN KEY (thumbnail_id) REFERENCES men_post (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE men_post ADD CONSTRAINT FK_4318CE0E7EC5785 FOREIGN KEY (board_id) REFERENCES men_board (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE men_post ADD CONSTRAINT FK_4318CE0A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES men_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE men_post_tag ADD CONSTRAINT FK_B182CE0E4B89032C FOREIGN KEY (post_id) REFERENCES men_post (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE men_post_tag ADD CONSTRAINT FK_B182CE0EBAD26311 FOREIGN KEY (tag_id) REFERENCES men_tag (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

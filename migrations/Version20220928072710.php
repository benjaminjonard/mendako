<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220928072710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE men_board (id CHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B0B8D425E237E06 ON men_board (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B0B8D42989D9B62 ON men_board (slug)');
        $this->addSql('COMMENT ON COLUMN men_board.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN men_board.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE men_image (id CHAR(36) NOT NULL, board_id CHAR(36) DEFAULT NULL, uploaded_by_id CHAR(36) DEFAULT NULL, path VARCHAR(255) DEFAULT NULL, mimetype VARCHAR(255) NOT NULL, height INT NOT NULL, width INT NOT NULL, size INT NOT NULL, duration INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F660A25AB548B0F ON men_image (path)');
        $this->addSql('CREATE INDEX IDX_F660A25AE7EC5785 ON men_image (board_id)');
        $this->addSql('CREATE INDEX IDX_F660A25AA2B28FE8 ON men_image (uploaded_by_id)');
        $this->addSql('COMMENT ON COLUMN men_image.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN men_image.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE men_image_tag (image_id CHAR(36) NOT NULL, tag_id CHAR(36) NOT NULL, PRIMARY KEY(image_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_18DF43F3DA5256D ON men_image_tag (image_id)');
        $this->addSql('CREATE INDEX IDX_18DF43FBAD26311 ON men_image_tag (tag_id)');
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
        $this->addSql('ALTER TABLE men_image ADD CONSTRAINT FK_F660A25AE7EC5785 FOREIGN KEY (board_id) REFERENCES men_board (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE men_image ADD CONSTRAINT FK_F660A25AA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES men_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE men_image_tag ADD CONSTRAINT FK_18DF43F3DA5256D FOREIGN KEY (image_id) REFERENCES men_image (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE men_image_tag ADD CONSTRAINT FK_18DF43FBAD26311 FOREIGN KEY (tag_id) REFERENCES men_tag (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE men_image DROP CONSTRAINT FK_F660A25AE7EC5785');
        $this->addSql('ALTER TABLE men_image DROP CONSTRAINT FK_F660A25AA2B28FE8');
        $this->addSql('ALTER TABLE men_image_tag DROP CONSTRAINT FK_18DF43F3DA5256D');
        $this->addSql('ALTER TABLE men_image_tag DROP CONSTRAINT FK_18DF43FBAD26311');
        $this->addSql('DROP TABLE men_board');
        $this->addSql('DROP TABLE men_image');
        $this->addSql('DROP TABLE men_image_tag');
        $this->addSql('DROP TABLE men_tag');
        $this->addSql('DROP TABLE men_user');
    }
}

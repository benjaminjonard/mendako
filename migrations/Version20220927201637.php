<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220927201637 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE men_user (id CHAR(36) NOT NULL, username VARCHAR(32) NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL, roles TEXT NOT NULL, timezone VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D3283624F85E0677 ON men_user (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D3283624E7927C74 ON men_user (email)');
        $this->addSql('COMMENT ON COLUMN men_user.roles IS \'(DC2Type:simple_array)\'');
        $this->addSql('COMMENT ON COLUMN men_user.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN men_user.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP TABLE men_user');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220928091453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE men_board ADD thumbnail_id CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE men_board ADD CONSTRAINT FK_6B0B8D42FDFF2E92 FOREIGN KEY (thumbnail_id) REFERENCES men_image (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_6B0B8D42FDFF2E92 ON men_board (thumbnail_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE men_board DROP CONSTRAINT FK_6B0B8D42FDFF2E92');
        $this->addSql('DROP INDEX IDX_6B0B8D42FDFF2E92');
        $this->addSql('ALTER TABLE men_board DROP thumbnail_id');
    }
}

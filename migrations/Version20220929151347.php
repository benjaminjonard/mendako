<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220929151347 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE men_board DROP CONSTRAINT FK_6B0B8D42FDFF2E92');
        $this->addSql('ALTER TABLE men_board ADD CONSTRAINT FK_6B0B8D42FDFF2E92 FOREIGN KEY (thumbnail_id) REFERENCES men_post (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE men_board DROP CONSTRAINT fk_6b0b8d42fdff2e92');
        $this->addSql('ALTER TABLE men_board ADD CONSTRAINT fk_6b0b8d42fdff2e92 FOREIGN KEY (thumbnail_id) REFERENCES men_post (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}

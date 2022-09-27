<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220927201737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE men_image ADD uploaded_by_id CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE men_image ADD CONSTRAINT FK_F660A25AA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES men_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F660A25AA2B28FE8 ON men_image (uploaded_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE men_image DROP CONSTRAINT FK_F660A25AA2B28FE8');
        $this->addSql('DROP INDEX IDX_F660A25AA2B28FE8');
        $this->addSql('ALTER TABLE men_image DROP uploaded_by_id');
    }
}

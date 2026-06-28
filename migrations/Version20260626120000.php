<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add men_staged_upload table for bulk un-sorted uploads (staging area)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('
            CREATE TABLE men_staged_upload (
                id CHAR(36) NOT NULL,
                uploaded_by_id CHAR(36) DEFAULT NULL,
                path VARCHAR(255) DEFAULT NULL,
                mimetype VARCHAR(255) NOT NULL,
                height INT DEFAULT NULL,
                width INT DEFAULT NULL,
                size INT NOT NULL,
                duration INT DEFAULT NULL,
                has_sound BOOLEAN DEFAULT false NOT NULL,
                vector vector(271) DEFAULT NULL,
                is_duplicate BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        ');
        $this->addSql('CREATE UNIQUE INDEX uniq_men_staged_upload_path ON men_staged_upload (path)');
        $this->addSql('CREATE INDEX idx_men_staged_upload_uploaded_by ON men_staged_upload (uploaded_by_id)');
        $this->addSql('ALTER TABLE men_staged_upload ADD CONSTRAINT fk_men_staged_upload_uploaded_by FOREIGN KEY (uploaded_by_id) REFERENCES men_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

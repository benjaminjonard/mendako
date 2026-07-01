<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add men_tag_suggestion table (non-authoritative automatic tag suggestions)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

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
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('DROP TABLE men_tag_suggestion');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260125155325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] Move duplicate detection to pgvector (64-dim binary DCT pHash)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        $this->addSql('ALTER TABLE men_post_signature_word DROP CONSTRAINT fk_3cf651044b89032c');
        $this->addSql('DROP TABLE men_post_signature_word');
        $this->addSql('ALTER TABLE men_post ADD vector vector(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE men_post DROP signature');

        $this->addSql('
            CREATE INDEX idx_post_vector_hnsw
            ON men_post
            USING hnsw (vector vector_l2_ops)
            WITH (m = 16, ef_construction = 64)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

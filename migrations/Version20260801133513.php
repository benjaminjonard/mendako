<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801133513 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recreate the HNSW index on men_post.vector, dropped by Version20260703092721.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('
            CREATE INDEX IF NOT EXISTS idx_post_vector_hnsw
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

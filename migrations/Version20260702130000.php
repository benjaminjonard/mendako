<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename the "Staging" feature to "Bulk upload": men_staged_upload -> men_bulk_upload (plus its
 * indexes/constraint), and migrate the polymorphic target_type discriminator 'staged' -> 'bulk'
 * on men_tag_suggestion and men_embedding. The physical upload directory (uploads/staging) and the
 * stored file paths are intentionally left unchanged so existing files stay reachable.
 */
final class Version20260702130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename Staging to Bulk upload (men_staged_upload -> men_bulk_upload, target_type staged -> bulk)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE men_staged_upload RENAME TO men_bulk_upload');
        $this->addSql('ALTER INDEX men_staged_upload_pkey RENAME TO men_bulk_upload_pkey');
        $this->addSql('ALTER INDEX uniq_men_staged_upload_path RENAME TO uniq_men_bulk_upload_path');
        $this->addSql('ALTER INDEX idx_men_staged_upload_uploaded_by RENAME TO idx_men_bulk_upload_uploaded_by');
        $this->addSql('ALTER TABLE men_bulk_upload RENAME CONSTRAINT fk_men_staged_upload_uploaded_by TO fk_men_bulk_upload_uploaded_by');

        $this->addSql("UPDATE men_tag_suggestion SET target_type = 'bulk' WHERE target_type = 'staged'");
        $this->addSql("UPDATE men_embedding SET target_type = 'bulk' WHERE target_type = 'staged'");
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql("UPDATE men_embedding SET target_type = 'staged' WHERE target_type = 'bulk'");
        $this->addSql("UPDATE men_tag_suggestion SET target_type = 'staged' WHERE target_type = 'bulk'");

        $this->addSql('ALTER TABLE men_bulk_upload RENAME CONSTRAINT fk_men_bulk_upload_uploaded_by TO fk_men_staged_upload_uploaded_by');
        $this->addSql('ALTER INDEX idx_men_bulk_upload_uploaded_by RENAME TO idx_men_staged_upload_uploaded_by');
        $this->addSql('ALTER INDEX uniq_men_bulk_upload_path RENAME TO uniq_men_staged_upload_path');
        $this->addSql('ALTER INDEX men_bulk_upload_pkey RENAME TO men_staged_upload_pkey');
        $this->addSql('ALTER TABLE men_bulk_upload RENAME TO men_staged_upload');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704165520 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add source column to men_tag (custom/wd provenance for auto-tagging)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE men_tag ADD source VARCHAR(16) DEFAULT \'custom\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230317114105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] Add theme on User';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE men_user ADD theme VARCHAR(255) DEFAULT \'browser\' NOT NULL');
        $this->addSql('ALTER TABLE men_user DROP dark_mode_enabled');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230817161502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] Make `height` and `width` nullable for `men_post`';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE men_post ALTER height DROP NOT NULL');
        $this->addSql('ALTER TABLE men_post ALTER width DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

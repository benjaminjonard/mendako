<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240716145112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] Add `comment` on `men_post`';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE men_post ADD comment TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

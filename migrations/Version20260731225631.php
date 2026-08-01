<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731225631 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the generated thumbnail path on posts, staged posts and boards';
    }

    // migrations:diff also re-proposes `ALTER ... vector TYPE` on every run, DBAL being unable to
    // introspect a custom type's dimension; those columns are already correct, so it is dropped.

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE men_board ADD thumbnail_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE men_post ADD thumbnail_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE men_staged_post ADD thumbnail_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

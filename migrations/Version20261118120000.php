<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] Rename messenger table to doctrine_messenger_message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE messenger_messages RENAME TO doctrine_messenger_message');
        $this->addSql('ALTER INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 RENAME TO IDX_FA96D316FB7336F0E3BD61CE16BA31DBBF396750');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

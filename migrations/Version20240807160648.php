<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240807160648 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] Update image similarity properties';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE men_post_signature_word DROP CONSTRAINT fk_3cf651044b89032c');
        $this->addSql('DROP TABLE men_post_signature_word');

        $this->addSql('ALTER TABLE men_post DROP signature');
        $this->addSql('ALTER TABLE men_post ADD hash0 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE men_post ADD hash1 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE men_post ADD hash2 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE men_post ADD hash3 VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

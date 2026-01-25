<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230513081258 extends AbstractMigration
{
    private $container;

    public function getDescription(): string
    {
        return '[Postgresql] Add signature properties for checking images similarities';
    }

    public function setContainer($container)
    {
        $this->container = $container;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE TABLE men_post_signature_word (id CHAR(36) NOT NULL, post_id CHAR(36) DEFAULT NULL, word VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_3CF651044B89032C ON men_post_signature_word (post_id)');
        $this->addSql('ALTER TABLE men_post_signature_word ADD CONSTRAINT FK_3CF651044B89032C FOREIGN KEY (post_id) REFERENCES men_post (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE men_post ADD signature VARCHAR(255)');

        $this->addSql('CREATE INDEX idx_post_signature_word_word ON men_post_signature_word (word)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

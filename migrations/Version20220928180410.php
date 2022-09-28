<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Enum\TagCategory;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220928180410 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $category = TagCategory::META->value;

        $id = Uuid::v4()->toRfc4122();
        $this->addSql("INSERT INTO men_tag (id, name, category, created_at) VALUES ('$id', 'animated', '$category', NOW())");

        $id = Uuid::v4()->toRfc4122();
        $this->addSql("INSERT INTO men_tag (id, name, category, created_at) VALUES ('$id', 'with_sound', '$category', NOW())");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
    }
}

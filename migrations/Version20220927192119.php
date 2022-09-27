<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Enum\TagCategory;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20220927192119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $category = TagCategory::META->value;

        $id = Uuid::v4()->toRfc4122();
        $this->addSql("INSERT INTO men_tag (id, name, slug, category, created_at) VALUES ('$id', 'animated', 'animated', '$category', NOW())");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
    }
}

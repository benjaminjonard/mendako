<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Enum\TagCategory;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20220929153937 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[Postgresql] Add required meta tags.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $category = TagCategory::META->value;

        $id = Uuid::v4()->toRfc4122();
        $this->addSql("INSERT INTO men_tag (id, name, category, created_at) VALUES ('$id', 'animated', '$category', NOW())");

        $id = Uuid::v4()->toRfc4122();
        $this->addSql("INSERT INTO men_tag (id, name, category, created_at) VALUES ('$id', 'with_sound', '$category', NOW())");

        $id = Uuid::v4()->toRfc4122();
        $this->addSql("INSERT INTO men_tag (id, name, category, created_at) VALUES ('$id', 'video', '$category', NOW())");

        $id = Uuid::v4()->toRfc4122();
        $this->addSql("INSERT INTO men_tag (id, name, category, created_at) VALUES ('$id', 'gif', '$category', NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\PostVectorService;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\File\File;

final class Version20260125155325 extends AbstractMigration
{
    private $container;

    public function getDescription(): string
    {
        return '[Postgresql] Move to pgvector for duplicate detection `';
    }

    public function setContainer($container)
    {
        $this->container = $container;
    }

    public function up(Schema $schema): void
    {
        ini_set('memory_limit', '-1');

        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        $this->addSql('ALTER TABLE men_post_signature_word DROP CONSTRAINT fk_3cf651044b89032c');
        $this->addSql('DROP TABLE men_post_signature_word');
        $this->addSql('ALTER TABLE men_post ADD vector vector(271) DEFAULT NULL');
        $this->addSql('ALTER TABLE men_post DROP signature');

        $this->addSql('
            CREATE INDEX idx_post_vector_hnsw 
            ON men_post 
            USING hnsw (vector vector_l2_ops)
            WITH (m = 16, ef_construction = 64)
        ');

        $publicPath = $this->container->getParameter('kernel.project_dir') . '/public';
        $postVectorService = $this->container->get(PostVectorService::class);

        $posts = $this->connection->createQueryBuilder()->select('id, path')->from('men_post')->executeQuery()->fetchAllAssociative();
        foreach ($posts as $result) {
            $file = new File($publicPath . '/' . $result['path']);
            $vector = $postVectorService->generateVector($file);
            $postId = $result['id'];

            if ($vector) {
                $this->addSql("UPDATE men_post SET vector = '$vector' WHERE id = '$postId'");
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

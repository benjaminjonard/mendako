<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\Post;
use App\Service\SimilarityChecker;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;

final class Version20230513081258 extends AbstractMigration implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    public function getDescription(): string
    {
        return '[Postgresql] Add signature properties for checking images similarities';
    }

    public function up(Schema $schema): void
    {
        $similarityChecker = $this->container->get(SimilarityChecker::class);
        $publicPath = $this->container->getParameter('kernel.project_dir') . '/public';

        $this->skipIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE TABLE men_post_signature_word (id CHAR(36) NOT NULL, post_id CHAR(36) DEFAULT NULL, word VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_3CF651044B89032C ON men_post_signature_word (post_id)');
        $this->addSql('ALTER TABLE men_post_signature_word ADD CONSTRAINT FK_3CF651044B89032C FOREIGN KEY (post_id) REFERENCES men_post (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE men_post ADD signature VARCHAR(255)');

        $this->addSql('CREATE INDEX idx_post_signature_word_word ON men_post_signature_word (word)');

        $results = $this->connection->createQueryBuilder()->select('id, path')->from('men_post')->executeQuery()->fetchAllAssociative();
        foreach ($results as $result) {
            $post = new Post();
            $post->setFile(new File($publicPath . '/' . $result['path']));
            $similarityChecker->generateSignature($post);

            $postId = $result['id'];
            $signature = $post->getSignature();
            if ($signature === null) {
                continue;
            }

            $this->addSql("UPDATE men_post SET signature = '$signature' WHERE id = '$postId'");

            foreach ($post->getSignatureWords() as $word) {
                $id = Uuid::v4()->toRfc4122();
                $word = $word->getWord();
                $this->addSql("INSERT INTO men_post_signature_word (id, post_id, word) VALUES ('$id', '$postId', '$word')");
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

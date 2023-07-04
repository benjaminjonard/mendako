<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\Stream;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

final class Version20230704092159 extends AbstractMigration implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    public function getDescription(): string
    {
        return '[Postgresql] Add hasSound property to `men_post`';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $publicPath = $this->container->getParameter('kernel.project_dir') . '/public';

        $this->addSql('ALTER TABLE men_post ADD has_sound BOOLEAN DEFAULT false NOT NULL');

        $results = $this->connection->createQueryBuilder()
            ->select('id, path')
            ->from('men_post')
            ->where("mimetype IN ('video/mp4', 'video/webm')")
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        foreach ($results as $result) {
            $ffmpeg = FFMpeg::create();
            $video = $ffmpeg->open($publicPath.'/'.$result['path']);
            $hasSound = $video->getStreams()->audios()->first() instanceof Stream ? 'true' : 'false';
            $postId = $result['id'];

            $this->addSql("UPDATE men_post SET has_sound = $hasSound WHERE id = '$postId'");
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Always move forward.');
    }
}

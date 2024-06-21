<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\UploadAnnotationReader;
use App\Entity\Post;
use App\Service\AutomatedTagger;
use App\Service\SimilarityChecker;
use App\Service\Uploader;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsDoctrineListener(event: Events::onFlush)]
final class PostListener
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Post) {
                $uow = $args->getObjectManager()->getUnitOfWork();
                $changeset = $uow->getEntityChangeSet($entity);

                if (isset($changeset['board'])) {
                    $oldPath = $this->publicPath . '/' . $entity->getPath();
                    $relativeNewPath = 'uploads/boards/' . $entity->getBoard()->getId();
                    $absoluteNewPath = $this->publicPath . '/' . $relativeNewPath;

                    $filesystem = new Filesystem();
                    $filesystem->mkdir($absoluteNewPath);
                    $filesystem->rename($oldPath, $absoluteNewPath . '/' . basename($entity->getPath()));

                    $entity->setPath($relativeNewPath . '/' . basename($entity->getPath()));
                    $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($entity::class), $entity);
                }
            }
        }
    }
}

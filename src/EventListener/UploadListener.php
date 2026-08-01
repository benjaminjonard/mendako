<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\UploadAnnotationReader;
use App\Entity\Post;
use App\Entity\ThumbnailableInterface;
use App\Service\AutomatedTagger;
use App\Service\ThumbnailDispatcher;
use App\Service\Uploader;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postLoad)]
final readonly class UploadListener
{
    public function __construct(
        private UploadAnnotationReader $reader,
        private Uploader $uploader,
        private AutomatedTagger $automatedTagger,
        private ThumbnailDispatcher $thumbnailDispatcher
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->handleUpload($args->getObject());
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->handleUpload($entity)) {
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($entity::class), $entity);
            }
        }
    }

    /**
     * Returns whether the entity carries any upload field, which is what tells onFlush a change set
     * needs recomputing.
     */
    private function handleUpload(object $entity): bool
    {
        $fields = $this->reader->getUploadFields($entity);

        foreach ($fields as $property => $attribute) {
            $uploaded = $this->uploader->upload($entity, $property, $attribute);
            if ($entity instanceof Post) {
                $this->automatedTagger->tag($entity);
            }
            if ($uploaded && $entity instanceof ThumbnailableInterface) {
                $this->thumbnailDispatcher->schedule($entity);
            }
        }

        return $fields !== [];
    }

    public function postFlush(): void
    {
        $this->thumbnailDispatcher->dispatchScheduled();
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        foreach ($this->reader->getUploadFields($entity) as $property => $attribute) {
            $this->uploader->setFileFromFilename($entity, $property, $attribute);
        }
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        foreach ($this->reader->getUploadFields($entity) as $attribute) {
            $this->uploader->removeOldFile($entity, $attribute);
        }
    }
}

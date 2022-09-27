<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\UploadAnnotationReader;
use App\Service\Uploader;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;

final class UploadListener
{
    public function __construct(
        private readonly UploadAnnotationReader $reader,
        private readonly Uploader $uploader
    ) {
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        foreach ($this->reader->getUploadFields($entity) as $property => $attribute) {
            $this->uploader->upload($entity, $property, $attribute);
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            foreach ($this->reader->getUploadFields($entity) as $property => $attribute) {
                $this->uploader->upload($entity, $property, $attribute);
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(\get_class($entity)), $entity);
            }
        }
    }

    public function postLoad(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        foreach ($this->reader->getUploadFields($entity) as $property => $attribute) {
            $this->uploader->setFileFromFilename($entity, $property, $attribute);
        }
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        foreach ($this->reader->getUploadFields($entity) as $attribute) {
            $this->uploader->removeOldFile($entity, $attribute);
        }
    }
}

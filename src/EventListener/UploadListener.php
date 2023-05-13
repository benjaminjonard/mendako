<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\UploadAnnotationReader;
use App\Service\AutomatedTagger;
use App\Service\SimilarityChecker;
use App\Service\Uploader;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;

final readonly class UploadListener
{
    public function __construct(
        private UploadAnnotationReader $reader,
        private Uploader $uploader,
        private AutomatedTagger $automatedTagger
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        foreach ($this->reader->getUploadFields($entity) as $property => $attribute) {
            $this->uploader->upload($entity, $property, $attribute);
            $this->automatedTagger->tag($entity);
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            foreach ($this->reader->getUploadFields($entity) as $property => $attribute) {
                $this->uploader->upload($entity, $property, $attribute);
                $this->automatedTagger->tag($entity);
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($entity::class), $entity);
            }
        }
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

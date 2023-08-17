<?php

declare(strict_types=1);

namespace App\Service;

use App\Attribute\Upload;
use App\Entity\Post;
use Contao\ImagineSvg\Imagine;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\Stream;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class Uploader
{
    private readonly PropertyAccessor $accessor;

    public function __construct(
        private readonly RandomStringGenerator $randomStringGenerator,
        private readonly string $publicPath
    )
    {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    public function upload(Post $entity, string $property, Upload $attribute): void
    {
        $file = $this->accessor->getValue($entity, $property);
        if ($file instanceof UploadedFile) {
            $relativePath = 'uploads/boards/' . $entity->getBoard()->getId() . '/';
            $absolutePath = $this->publicPath . '/' . $relativePath;

            if (!is_dir($absolutePath) && !mkdir($absolutePath, recursive: true) && !is_dir($absolutePath)) {
                throw new \Exception('There was a problem while uploading the file. Please try again!');
            }

            $generatedName = $this->randomStringGenerator->generate(20);
            $extension = $file->guessExtension();

            $fileName = $generatedName . '.' . $extension;
            $file->move($absolutePath, $fileName);

            $entity
                ->setMimetype(mime_content_type($absolutePath . $fileName))
                ->setSize(filesize($absolutePath . $fileName))
            ;

            if ($entity->getMimetype() === 'video/mp4' || $entity->getMimetype() === 'video/webm' || $entity->getMimetype() === 'image/gif') {
                $ffmpeg = FFMpeg::create();
                $video = $ffmpeg->open($absolutePath . $fileName);
                $stream = $video->getStreams()->videos()->first();
                $hasSound = $video->getStreams()->audios()->first() instanceof Stream;

                $entity
                    ->setDuration((int) round((float) $video->getFormat()->get('duration')))
                    ->setHeight($stream->getDimensions()->getHeight())
                    ->setWidth($stream->getDimensions()->getWidth())
                    ->setHasSound($hasSound)
                ;
            } else if ($entity->getMimetype() === 'image/svg+xml') {
                $size = (new Imagine())
                    ->open($absolutePath . $fileName)
                    ->getSize();

                $entity
                    ->setWidth($size->getWidth())
                    ->setHeight($size->getHeight())
                ;
            } else {
                $dimensions = getimagesize($absolutePath . $fileName);
                $entity
                    ->setWidth($dimensions[0])
                    ->setHeight($dimensions[1])
                ;
            }

            $this->removeOldFile($entity, $attribute);
            $this->accessor->setValue($entity, $attribute->getPath(), $relativePath . $fileName);
        }
    }

    public function removeOldFile(object $entity, Upload $attribute): void
    {
        if (null !== $attribute->getPath()) {
            $path = $this->accessor->getValue($entity, $attribute->getPath());
            if (null !== $path) {
                @unlink($this->publicPath . '/' . $path);
            }
        }
    }

    public function setFileFromFilename(object $entity, string $property, Upload $attribute): void
    {
        $path = $this->accessor->getValue($entity, $attribute->getPath());

        if (null !== $path) {
            $file = new File($this->publicPath . '/' . $path, false);
            $this->accessor->setValue($entity, $property, $file);
        }
    }
}

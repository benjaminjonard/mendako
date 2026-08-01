<?php

declare(strict_types=1);

namespace App\Service;

use App\Attribute\Upload;
use App\Entity\UploadableInterface;
use Contao\ImagineSvg\Imagine;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\Stream;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class Uploader
{
    private readonly PropertyAccessor $accessor;

    public function __construct(
        private readonly RandomStringGenerator $randomStringGenerator,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath
    )
    {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    /**
     * Returns whether a file was actually moved, which is what tells an ordinary update apart
     * from a genuine (re)upload.
     */
    public function upload(UploadableInterface $entity, string $property, Upload $attribute): bool
    {
        $file = $this->accessor->getValue($entity, $property);
        if ($file instanceof UploadedFile) {
            $relativePath = $entity->getUploadRelativeDirectory() . '/';
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

            if ($entity->getMimetype() === 'video/mp4' || $entity->getMimetype() === 'video/webm' || $entity->getMimetype() === 'image/gif' || $entity->getMimetype() === 'video/x-m4v') {
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
            } elseif ($entity->getMimetype() === 'image/svg+xml') {
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

            return true;
        }

        return false;
    }

    public function removeOldFile(object $entity, Upload $attribute): void
    {
        foreach ([$attribute->getPath(), $attribute->getThumbnailPath()] as $property) {
            if (null === $property) {
                continue;
            }

            $path = $this->accessor->getValue($entity, $property);
            if (null !== $path) {
                @unlink($this->publicPath . '/' . $path);
            }

            $this->accessor->setValue($entity, $property, null);
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

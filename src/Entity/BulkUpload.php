<?php

declare(strict_types=1);

namespace App\Entity;

use App\Attribute\Upload;
use App\Repository\BulkUploadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BulkUploadRepository::class)]
#[ORM\Table(name: 'men_bulk_upload')]
class BulkUpload implements UploadableInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36, unique: true, options: ['fixed' => true])]
    private string $id;

    #[Upload(path: 'path')]
    #[Assert\File(mimeTypes: ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif', 'image/svg+xml', 'video/mp4', 'video/webm', 'video/x-m4v'])]
    private ?File $file = null;

    #[ORM\Column(type: Types::STRING, nullable: true, unique: true)]
    private ?string $path = null;

    #[ORM\Column(type: Types::STRING)]
    private ?string $mimetype = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $size = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $duration = null; //in seconds

    #[ORM\Column(type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $hasSound = false;

    #[ORM\Column(type: 'vector', nullable: true, options: ['dimensions' => 64])]
    private ?string $vector = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $isDuplicate = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $uploadedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
    }

    public function getUploadRelativeDirectory(): string
    {
        return 'uploads/bulk-upload';
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(?File $file): BulkUpload
    {
        $this->file = $file;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): BulkUpload
    {
        $this->path = $path;

        return $this;
    }

    public function getMimetype(): ?string
    {
        return $this->mimetype;
    }

    public function setMimetype(?string $mimetype): BulkUpload
    {
        $this->mimetype = $mimetype;

        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): BulkUpload
    {
        $this->height = $height;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): BulkUpload
    {
        $this->width = $width;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): BulkUpload
    {
        $this->size = $size;

        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): BulkUpload
    {
        $this->duration = $duration;

        return $this;
    }

    public function hasSound(): bool
    {
        return $this->hasSound;
    }

    public function setHasSound(bool $hasSound): BulkUpload
    {
        $this->hasSound = $hasSound;

        return $this;
    }

    public function getVector(): ?string
    {
        return $this->vector;
    }

    public function setVector(?string $vector): BulkUpload
    {
        $this->vector = $vector;

        return $this;
    }

    public function isDuplicate(): bool
    {
        return $this->isDuplicate;
    }

    public function setIsDuplicate(bool $isDuplicate): BulkUpload
    {
        $this->isDuplicate = $isDuplicate;

        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): BulkUpload
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): BulkUpload
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Attribute\Upload;
use App\Repository\StagedUploadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: StagedUploadRepository::class)]
#[ORM\Table(name: 'men_staged_upload')]
class StagedUpload implements UploadableInterface
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

    #[ORM\Column(type: 'vector', nullable: true, options: ['dimensions' => 271])]
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
        return 'uploads/staging';
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(?File $file): StagedUpload
    {
        $this->file = $file;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): StagedUpload
    {
        $this->path = $path;

        return $this;
    }

    public function getMimetype(): ?string
    {
        return $this->mimetype;
    }

    public function setMimetype(?string $mimetype): StagedUpload
    {
        $this->mimetype = $mimetype;

        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): StagedUpload
    {
        $this->height = $height;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): StagedUpload
    {
        $this->width = $width;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): StagedUpload
    {
        $this->size = $size;

        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): StagedUpload
    {
        $this->duration = $duration;

        return $this;
    }

    public function hasSound(): bool
    {
        return $this->hasSound;
    }

    public function setHasSound(bool $hasSound): StagedUpload
    {
        $this->hasSound = $hasSound;

        return $this;
    }

    public function getVector(): ?string
    {
        return $this->vector;
    }

    public function setVector(?string $vector): StagedUpload
    {
        $this->vector = $vector;

        return $this;
    }

    public function isDuplicate(): bool
    {
        return $this->isDuplicate;
    }

    public function setIsDuplicate(bool $isDuplicate): StagedUpload
    {
        $this->isDuplicate = $isDuplicate;

        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): StagedUpload
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): StagedUpload
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}

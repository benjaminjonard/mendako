<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoardRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BoardRepository::class)]
#[ORM\Table(name: 'men_board')]
class Board
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36, unique: true, options: ['fixed' => true])]
    private string $id;

    #[ORM\Column(type: Types::STRING, unique: true)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, unique: true)]
    #[Gedmo\Slug(fields: ['name'])]
    private ?string $slug;

    #[ORM\ManyToOne(targetEntity: Image::class)]
    private ?Image $thumbnail = null;

    #[ORM\OneToMany(targetEntity: Image::class, mappedBy: 'board', cascade: ['all'], fetch: 'EXTRA_LAZY')]
    private Collection $images;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Gedmo\Timestampable(on: 'change', field: ['name'])]
    private ?\DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): Board
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): Board
    {
        $this->slug = $slug;

        return $this;
    }

    public function getThumbnail(): ?Image
    {
        return $this->thumbnail;
    }

    public function setThumbnail(?Image $thumbnail): Board
    {
        $this->thumbnail = $thumbnail;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): Board
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): Board
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}

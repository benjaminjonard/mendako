<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoardRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BoardRepository::class)]
#[ORM\Table(name: 'men_board')]
#[UniqueEntity(fields: ['name'], message: 'error.name.not_unique')]
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
    private ?string $slug = null;

    #[ORM\ManyToOne(targetEntity: Post::class, cascade: ['all'])]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Post $thumbnail = null;

    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'board', cascade: ['all'], fetch: 'EXTRA_LAZY')]
    private Collection $posts;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Gedmo\Timestampable(on: 'change', field: ['name'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->posts = new \Doctrine\Common\Collections\ArrayCollection();
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

    public function getThumbnail(): ?Post
    {
        return $this->thumbnail;
    }

    public function setThumbnail(?Post $thumbnail): Board
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

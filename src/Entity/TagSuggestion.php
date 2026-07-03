<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TagCategory;
use App\Repository\TagSuggestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TagSuggestionRepository::class)]
#[ORM\Table(name: 'men_tag_suggestion')]
#[ORM\Index(name: 'idx_tag_suggestion_target', columns: ['target_type', 'target_id'])]
#[ORM\UniqueConstraint(name: 'uniq_tag_suggestion_target_source_name', columns: ['target_type', 'target_id', 'source', 'tag_name'])]
class TagSuggestion
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DISMISSED = 'dismissed';

    public const SOURCE_WD = 'wd';
    public const SOURCE_KNN = 'knn';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36, unique: true, options: ['fixed' => true])]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $targetType;

    #[ORM\Column(type: Types::STRING, length: 36, options: ['fixed' => true])]
    private string $targetId;

    #[ORM\Column(type: Types::STRING)]
    private string $tagName;

    #[ORM\Column(type: Types::STRING, nullable: true, enumType: TagCategory::class)]
    private ?TagCategory $category = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $score = 0.0;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $source = self::SOURCE_WD;

    #[ORM\Column(type: Types::STRING, length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function setTargetType(string $targetType): self
    {
        $this->targetType = $targetType;

        return $this;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function setTargetId(string $targetId): self
    {
        $this->targetId = $targetId;

        return $this;
    }

    public function getTagName(): string
    {
        return $this->tagName;
    }

    public function setTagName(string $tagName): self
    {
        $this->tagName = $tagName;

        return $this;
    }

    public function getCategory(): ?TagCategory
    {
        return $this->category;
    }

    public function setCategory(?TagCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function setScore(float $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}

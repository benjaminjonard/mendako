<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmbeddingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EmbeddingRepository::class)]
#[ORM\Table(name: 'men_embedding')]
#[ORM\Index(name: 'idx_embedding_target', columns: ['target_type', 'target_id'])]
#[ORM\UniqueConstraint(name: 'uniq_embedding_target_ordinal', columns: ['target_type', 'target_id', 'ordinal'])]
class Embedding
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36, unique: true, options: ['fixed' => true])]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $targetType;

    #[ORM\Column(type: Types::STRING, length: 36, options: ['fixed' => true])]
    private string $targetId;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $ordinal = 0;

    #[ORM\Column(type: Types::STRING)]
    private string $embeddingModelId;

    #[ORM\Column(type: 'vector', options: ['dimensions' => 1024])]
    private string $embeddingVector;

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

    public function getOrdinal(): int
    {
        return $this->ordinal;
    }

    public function setOrdinal(int $ordinal): self
    {
        $this->ordinal = $ordinal;

        return $this;
    }

    public function getEmbeddingModelId(): string
    {
        return $this->embeddingModelId;
    }

    public function setEmbeddingModelId(string $embeddingModelId): self
    {
        $this->embeddingModelId = $embeddingModelId;

        return $this;
    }

    public function getEmbeddingVector(): string
    {
        return $this->embeddingVector;
    }

    public function setEmbeddingVector(string $embeddingVector): self
    {
        $this->embeddingVector = $embeddingVector;

        return $this;
    }
}

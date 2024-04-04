<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'men_post_signature_word')]
#[ORM\Index(name: 'idx_post_signature_word_word', columns: ['word'])]
class PostSignatureWord
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36, unique: true, options: ['fixed' => true])]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'signatureWords')]
    #[Assert\NotBlank]
    private Post $post;

    #[ORM\Column(type: Types::STRING)]
    private string $word;

    public function __construct(Post $post, string $word)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->post = $post;
        $this->word = $word;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getWord(): string
    {
        return $this->word;
    }
}

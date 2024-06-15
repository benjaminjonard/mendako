<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaginationType;
use App\Enum\Theme;
use App\Repository\UserRepository;
use App\Validator as AppAssert;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'men_user')]
#[UniqueEntity(fields: ['email'], message: 'error.email.not_unique')]
#[UniqueEntity(fields: ['username'], message: 'error.username.not_unique')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, \Stringable
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36, unique: true, options: ['fixed' => true])]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 32, unique: true)]
    #[Assert\Regex(pattern: '/^[a-z\\d_]{2,32}$/i', message: 'error.username.incorrect')]
    private ?string $username = null;

    #[ORM\Column(type: Types::STRING, unique: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(type: Types::STRING)]
    private ?string $password = null;

    #[Assert\Regex(pattern: "/(?=^.{8,}\$)((?=.*\\d)|(?=.*\\W+))(?![.\n])(?=.*[A-Za-z]).*\$/", message: 'error.password.incorrect')]
    private ?string $plainPassword = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(type: Types::SIMPLE_ARRAY)]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(type: Types::STRING, length: 50)]
    #[Assert\Timezone]
    private ?string $timezone = null;

    #[ORM\Column(type: Types::STRING, length: 2, options: ['default' => 'en'])]
    #[AppAssert\AvailableLocale]
    private string $locale = 'en';

    #[ORM\Column(type: Types::STRING, options: ['default' => Theme::BROWSER->value])]
    #[Assert\Choice(choices: Theme::THEMES)]
    private string $theme = Theme::BROWSER->value;

    #[ORM\Column(type: Types::STRING, options: ['default' => PaginationType::PAGE->value])]
    #[Assert\Choice(choices: PaginationType::PAGINATION_TYPES)]
    private string $paginationType = PaginationType::PAGE->value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Gedmo\Timestampable(on: 'change', field: ['username', 'email', 'password', 'enabled', 'roles', 'timezone'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->getUsername();
    }

    public function __toString(): string
    {
        return (string) $this->getUsername();
    }

    public function __serialize()
    {
        return [$this->id, $this->username, $this->password];
    }

    public function __unserialize(array $data): void
    {
        [$this->id, $this->username, $this->password] = $data;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setSalt(?string $salt): User
    {
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): User
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): User
    {
        $this->plainPassword = $plainPassword;
        $this->password = $plainPassword;

        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): User
    {
        $this->roles = $roles;

        return $this;
    }

    public function addRole(string $role): User
    {
        $role = strtoupper($role);
        if (!\in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }

        return $this;
    }

    public function removeRole(string $role): User
    {
        if (false !== ($key = array_search(strtoupper($role), $this->roles, true))) {
            unset($this->roles[$key]);
            $this->roles = array_values($this->roles);
        }

        return $this;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setUsername(string $username): User
    {
        $this->username = $username;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): User
    {
        $this->email = $email;

        return $this;
    }

    public function isEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): User
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): User
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): User
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): User
    {
        $this->theme = $theme;

        return $this;
    }
    
    public function getPaginationType(): string
    {
        return $this->paginationType;
    }

    public function setPaginationType(string $paginationType): User
    {
        $this->paginationType = $paginationType;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): User
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): User
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}

<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'client_users')]
class ClientUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'client_id', type: Types::INTEGER)]
    private int $clientId;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(length: 200)]
    private string $login;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash = '';

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $isActive = true;

    public function __construct(int $clientId, string $email)
    {
        $this->clientId = $clientId;
        $this->email = mb_strtolower($email);
        $this->login = 'client:' . $this->email;
    }

    public function getId(): ?int { return $this->id; }
    public function getClientId(): int { return $this->clientId; }
    public function getEmail(): string { return $this->email; }
    public function isActive(): bool { return $this->isActive; }
    public function setPasswordHash(string $h): void { $this->passwordHash = $h; }

    public function getUserIdentifier(): string { return $this->login; }
    public function getPassword(): string { return $this->passwordHash; }
    public function getRoles(): array { return ['ROLE_CLIENT']; }
    public function eraseCredentials(): void {}
}
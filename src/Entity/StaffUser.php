<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'staff_users')]
class StaffUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'full_name', length: 160)]
    private string $fullName;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'role_id', nullable: false)]
    private Role $role;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'branch_id', nullable: true)]
    private ?Branch $branch = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $isActive = true;

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getFullName(): string { return $this->fullName; }
    public function getRole(): Role { return $this->role; }
    public function getBranch(): ?Branch { return $this->branch; }
    public function isActive(): bool { return $this->isActive; }

    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): string { return $this->passwordHash; }
    public function getRoles(): array
    {
        return ['ROLE_STAFF', 'ROLE_' . strtoupper($this->role->getCode())];
    }
    public function eraseCredentials(): void {}
}
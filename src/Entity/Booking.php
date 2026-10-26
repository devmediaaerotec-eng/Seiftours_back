<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bookings')]
class Booking
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'remaining_millimes', type: Types::INTEGER, insertable: false, updatable: false)]
    private ?int $remainingMillimes = null;           // colonne générée : lecture seule

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'customer_notes', type: Types::TEXT, nullable: true)]
    private ?string $customerNotes = null;

    public function __construct(
        #[ORM\Column(length: 20)] private string $reference,
        #[ORM\Column(length: 20)] private string $mode,           // 'agency' | 'deposit'
        #[ORM\Column(length: 30)] private string $status,         // voir ENUM du SQL
        #[ORM\Column(length: 20)] private string $source,
        #[ORM\ManyToOne, ORM\JoinColumn(name: 'customer_id', nullable: false)] private Customer $customer,
        #[ORM\ManyToOne, ORM\JoinColumn(name: 'branch_id', nullable: true)] private ?Branch $branch,
        #[ORM\Column(name: 'displayed_total_millimes', type: Types::INTEGER)] private int $displayedTotalMillimes,
        #[ORM\Column(name: 'total_millimes', type: Types::INTEGER)] private int $totalMillimes,
        #[ORM\Column(name: 'deposit_millimes', type: Types::INTEGER)] private int $depositMillimes = 0,
    ) {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getStatus(): string { return $this->status; }
    public function setCustomerNotes(?string $n): void { $this->customerNotes = $n; }
}
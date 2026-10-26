<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'booking_items')]
class BookingItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'item_type', length: 20)]
    private string $itemType = 'hotel';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(name: 'booking_id', nullable: false, onDelete: 'CASCADE')] private Booking $booking,
        #[ORM\Column(name: 'hotel_id', type: Types::INTEGER, nullable: true)] private ?int $hotelId,
        #[ORM\Column(name: 'hotel_name', length: 190)] private string $hotelName,
        #[ORM\Column(name: 'check_in', type: Types::DATE_IMMUTABLE)] private \DateTimeImmutable $checkIn,
        #[ORM\Column(name: 'check_out', type: Types::DATE_IMMUTABLE)] private \DateTimeImmutable $checkOut,
        #[ORM\Column(name: 'room_name', length: 190)] private string $roomName,
        #[ORM\Column(name: 'board_name', length: 120)] private string $boardName,
        #[ORM\Column(type: Types::JSON)] private array $occupancy,
        #[ORM\Column(name: 'price_millimes', type: Types::INTEGER)] private int $priceMillimes,
    ) {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
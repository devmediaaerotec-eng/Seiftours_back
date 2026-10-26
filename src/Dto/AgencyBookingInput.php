<?php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AgencyBookingInput
{
    #[Assert\NotBlank, Assert\Length(max: 100)]
    public string $firstName = '';
    #[Assert\NotBlank, Assert\Length(max: 100)]
    public string $lastName = '';
    #[Assert\NotBlank, Assert\Email]
    public string $email = '';
    #[Assert\Regex('/^\+[0-9]{8,15}$/', message: 'Format international requis, ex. +21698123456')]
    public string $phone = '';
    #[Assert\Choice(['Mlle', 'Mme', 'M.'])]
    public ?string $title = null;

    #[Assert\NotBlank]
    public string $branchCode = '';

    #[Assert\Positive]
    public ?int $hotelId = null;
    #[Assert\NotBlank]
    public string $hotelName = '';
    #[Assert\NotBlank, Assert\Date]
    public string $checkIn = '';
    #[Assert\NotBlank, Assert\Date]
    public string $checkOut = '';
    #[Assert\NotBlank]
    public string $roomName = '';
    public string $boardName = '';

    /** ex. [{"adults":2,"children":1,"childrenAges":[5]}] */
    #[Assert\Count(min: 1)]
    public array $occupancy = [];

    #[Assert\Positive]
    public int $displayedTotalMillimes = 0;

    public ?string $customerNotes = null;
}
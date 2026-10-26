<?php
namespace App\Controller\Public;

use App\Dto\AgencyBookingInput;
use App\Entity\Booking;
use App\Entity\BookingItem;
use App\Entity\Branch;
use App\Entity\Customer;
use App\Service\ReferenceGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;   // Symfony 6.4 : Annotation\Route fonctionne aussi

final class AgencyBookingController extends AbstractController
{
    #[Route('/api/public/bookings/agency', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] AgencyBookingInput $in,
        EntityManagerInterface $em,
        ReferenceGenerator $refs,
    ): JsonResponse {
        $branch = $em->getRepository(Branch::class)->findOneBy(['code' => $in->branchCode, 'isActive' => true]);
        if (!$branch) {
            return $this->json(['error' => 'branch_not_found'], Response::HTTP_NOT_FOUND);
        }

        $checkIn  = new \DateTimeImmutable($in->checkIn);
        $checkOut = new \DateTimeImmutable($in->checkOut);
        if ($checkOut <= $checkIn) {
            return $this->json(['error' => 'invalid_dates'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // TODO (production) : ne jamais faire confiance au prix du client.
        // Recalculer le total côté serveur (offre MyGo + markup_rules) et le mettre dans $total.
        $total = $in->displayedTotalMillimes;

        $booking = $em->wrapInTransaction(function () use ($in, $em, $refs, $branch, $checkIn, $checkOut, $total) {
            $customer = new Customer($in->firstName, $in->lastName, $in->email, $in->phone, $in->title);
            $booking  = new Booking(
                $refs->generate(), 'agency', 'pending_agency', 'website',
                $customer, $branch, $in->displayedTotalMillimes, $total,
            );
            $booking->setCustomerNotes($in->customerNotes);
            $item = new BookingItem(
                $booking, $in->hotelId, $in->hotelName, $checkIn, $checkOut,
                $in->roomName, $in->boardName, $in->occupancy, $total,
            );
            $em->persist($customer);
            $em->persist($booking);
            $em->persist($item);
            return $booking;
        });

        return $this->json([
            'reference' => $booking->getReference(),
            'status'    => $booking->getStatus(),
        ], Response::HTTP_CREATED);
    }
}
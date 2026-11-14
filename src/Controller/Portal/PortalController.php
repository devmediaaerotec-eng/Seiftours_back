<?php
namespace App\Controller\Portal;

use App\Entity\ClientUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/portal')]
final class PortalController extends AbstractController
{
    /** false : le client ne voit ni chauffeurs ni plaques. */
    private const SHOW_DRIVERS = false;

    public function __construct(private Connection $db) {}

    #[Route('/me', methods: ['GET'])]
    public function me(#[CurrentUser] ClientUser $u): JsonResponse
    {
        $name = $this->db->fetchOne('SELECT name FROM clients WHERE id = ?', [$u->getClientId()]);
        return $this->json(['email' => $u->getEmail(), 'client_name' => $name]);
    }

    #[Route('/missions', methods: ['GET'])]
    public function missions(Request $r, #[CurrentUser] ClientUser $u): JsonResponse
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $r->query->getString('from')) ? $r->query->getString('from') : date('Y-m-d', strtotime('-7 days'));
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $r->query->getString('to')) ? $r->query->getString('to') : date('Y-m-d', strtotime('+30 days'));

        $rows = $this->db->fetchAllAssociative(
            "SELECT m.id, m.title, m.leg, m.starts_at, m.ends_at, m.pickup_place, m.destination, m.status, m.group_ref,
                    b.plate_number AS bus_plate, CONCAT(d.first_name, ' ', d.last_name) AS driver_name
             FROM bus_missions m
             JOIN buses b ON b.id = m.bus_id JOIN drivers d ON d.id = m.driver_id
             WHERE m.client_id = ? AND m.starts_at < DATE_ADD(?, INTERVAL 1 DAY) AND m.ends_at >= ?
             ORDER BY m.starts_at, m.id",
            [$u->getClientId(), $to, $from]
        );

        // Une étape (même mission, même horaire, même sens) = une ligne, avec le nombre de bus
        $steps = [];
        foreach ($rows as $m) {
            $k = $m['group_ref'] . '|' . $m['leg'] . '|' . $m['starts_at'] . '|' . $m['ends_at'];
            $steps[$k] ??= [
                'title' => $m['title'], 'leg' => $m['leg'], 'starts_at' => $m['starts_at'], 'ends_at' => $m['ends_at'],
                'pickup_place' => $m['pickup_place'], 'destination' => $m['destination'], 'status' => $m['status'],
                'buses' => 0, 'vehicles' => [],
            ];
            $steps[$k]['buses']++;
            if (self::SHOW_DRIVERS) {
                $steps[$k]['vehicles'][] = ['plate' => $m['bus_plate'], 'driver' => $m['driver_name']];
            }
        }

        return $this->json(['items' => array_values($steps)]);
    }
}
<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class PublicFleetController extends AbstractController
{
    public function __construct(private Connection $db) {}

    #[Route('/api/public/fleet', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, bus_type, brand, model, seats, year_made, image_url
             FROM buses
             WHERE is_active = 1 AND status <> 'out_of_service'
             ORDER BY (image_url IS NULL), bus_type = 'microbus', seats DESC, id"
        );

        $summary = [];
        foreach (['minibus', 'microbus'] as $type) {
            $summary[$type] = ['count' => 0, 'min_seats' => null, 'max_seats' => null];
        }
        $buses = [];
        foreach ($rows as $r) {
            $seats = (int) $r['seats'];
            $s = &$summary[$r['bus_type']];
            $s['count']++;
            $s['min_seats'] = $s['min_seats'] === null ? $seats : min($s['min_seats'], $seats);
            $s['max_seats'] = $s['max_seats'] === null ? $seats : max($s['max_seats'], $seats);
            unset($s);

            $buses[] = [
                'id' => (int) $r['id'], 'bus_type' => $r['bus_type'], 'brand' => $r['brand'], 'model' => $r['model'],
                'seats' => $seats, 'year_made' => $r['year_made'] === null ? null : (int) $r['year_made'],
                'image_url' => $r['image_url'],
            ];
        }

        $res = $this->json(['buses' => $buses, 'summary' => $summary]);
        $res->setPublic();
        $res->setMaxAge(300);            // la flotte change rarement : 5 minutes de cache

        return $res;
    }
}
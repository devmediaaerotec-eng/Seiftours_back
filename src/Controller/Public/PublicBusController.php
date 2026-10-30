<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/public/buses')]
final class PublicBusController extends AbstractController
{
    private const TYPES = ['minibus', 'microbus'];

    // Prix par jour par défaut si la colonne n'existe pas encore dans `buses`
    private const DEFAULT_DAILY_PRICE = [
        'minibus' => 450.0,
        'microbus' => 280.0,
    ];

    private const SELECT = "
        b.id, b.code, b.bus_type, b.brand, b.model, b.seats,
        b.image_url, b.notes,
        b.price_per_day
    ";

    public function __construct(private Connection $db) {}

    /* ---------------------------------------------------------------------- */
    /*  Liste publique : uniquement les bus actifs et disponibles             */
    /* ---------------------------------------------------------------------- */
    #[Route('', methods: ['GET'], name: 'public_buses_list')]
    public function list(Request $r): JsonResponse
    {
        $where = ["b.is_active = 1", "b.status = 'available'"];
        $params = [];

        // Filtre par type
        $type = $r->query->getString('type');
        if (in_array($type, self::TYPES, true)) {
            $where[] = 'b.bus_type = ?';
            $params[] = $type;
        }

        // Filtre par capacité minimale
        $minSeats = $r->query->getInt('min_seats', 0);
        if ($minSeats > 0) {
            $where[] = 'b.seats >= ?';
            $params[] = $minSeats;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM buses b WHERE ' . implode(' AND ', $where) . ' ORDER BY b.seats ASC',
            $params
        );

        return $this->json([
            'items' => array_map(fn (array $b) => $this->present($b), $rows),
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /*  Détail d'un bus (public)                                              */
    /* ---------------------------------------------------------------------- */
    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'], name: 'public_buses_show')]
    public function show(int $id): JsonResponse
    {
        $row = $this->db->fetchAssociative(
            'SELECT ' . self::SELECT . ' FROM buses b WHERE b.id = ? AND b.is_active = 1',
            [$id]
        );

        if (!$row) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json($this->present($row));
    }

    /* ---------------------------------------------------------------------- */
    /*  Transformation "public safe" (aucune donnée sensible)                 */
    /* ---------------------------------------------------------------------- */
    private function present(array $r): array
    {
        $type = $r['bus_type'] ?? 'minibus';

        // Si la colonne price_per_day existe en base, on l'utilise.
        // Sinon, on applique le tarif par défaut par type.
        $price = isset($r['price_per_day']) && $r['price_per_day'] !== null
            ? (float) $r['price_per_day']
            : (self::DEFAULT_DAILY_PRICE[$type] ?? 300.0);

        return [
            'id'            => (string) $r['id'],
            'code'          => $r['code'],
            'name'          => trim(
                ($r['brand'] ?? '') . ' ' . ($r['model'] ?? '') .
                (($r['code'] ?? '') !== '' ? ' (' . $r['code'] . ')' : '')
            ) ?: ('Bus ' . $r['code']),
            'vehicle_type'  => $type === 'minibus' ? 'Minibus' : 'Microbus',
            'image_url'     => $r['image_url'] ?? null,
            'seats'         => (int) ($r['seats'] ?? 0),
            'transmission'  => 'Manuelle',
            'features'      => $this->features($type, (int) ($r['seats'] ?? 0)),
            'price_per_day' => round($price, 2),
            'notes'         => $r['notes'] ?? null,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /*  Features générées automatiquement selon type/capacité                 */
    /* ---------------------------------------------------------------------- */
    private function features(string $type, int $seats): array
    {
        $f = ['Climatisation', 'Chauffeur professionnel'];

        if ($type === 'minibus') {
            $f[] = 'Idéal groupes / familles';
            $f[] = $seats >= 20 ? 'Soute à bagages spacieuse' : 'Bagages inclus';
        } else {
            $f[] = 'Parfait pour excursions';
            $f[] = 'Bagages inclus';
        }

        return $f;
    }
}
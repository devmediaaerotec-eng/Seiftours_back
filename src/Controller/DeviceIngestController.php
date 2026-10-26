<?php
namespace App\Controller;

use App\Service\PositionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeviceIngestController extends AbstractController
{
    public function __construct(
        private Connection $db,
        private PositionService $positions,
        #[Autowire('%env(string:GPS_INGEST_KEY)%')] private string $key,
    ) {}

    /** Corps : { "device_id": "GPS-DEMO-04", "positions": [ { lat, lng, speed_kmh, heading, accuracy_m, recorded_at } ] } (ou une seule position à plat) */
    #[Route('/api/device/positions', methods: ['POST'])]
    public function __invoke(Request $r): JsonResponse
    {
        if ($this->key === '') {
            return $this->json(['error' => 'disabled', 'message' => 'Réception GPS désactivée (GPS_INGEST_KEY non défini).'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if (!hash_equals($this->key, (string) $r->headers->get('X-Device-Key', ''))) {
            return $this->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        $b = json_decode($r->getContent(), true);
        if (!is_array($b)) {
            return $this->json(['error' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }
        $deviceId = trim((string) ($b['device_id'] ?? ''));
        $busId = $deviceId === '' ? false : $this->db->fetchOne('SELECT id FROM buses WHERE gps_device_id = ? AND is_active = 1', [$deviceId]);
        if ($busId === false) {
            return $this->json(['error' => 'unknown_device', 'message' => 'Boîtier inconnu ou bus archivé.'], Response::HTTP_NOT_FOUND);
        }

        $list = isset($b['positions']) && is_array($b['positions']) ? $b['positions'] : [$b];
        if (count($list) > 100) {
            return $this->json(['error' => 'too_many', 'message' => '100 positions maximum par envoi.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $accepted = 0;
        $rejected = [];
        foreach ($list as $i => $p) {
            if (!is_array($p) || !is_numeric($p['lat'] ?? null) || !is_numeric($p['lng'] ?? null)) {
                $rejected[$i] = 'lat et lng numériques requis.';
                continue;
            }
            $at = null;
            if (!empty($p['recorded_at'])) {
                try {
                    $at = new \DateTimeImmutable((string) $p['recorded_at']);
                } catch (\Throwable) {
                    $rejected[$i] = 'recorded_at invalide (format ISO 8601 attendu).';
                    continue;
                }
            }
            [$ok, $err] = $this->positions->record(
                (int) $busId, (float) $p['lat'], (float) $p['lng'],
                is_numeric($p['speed_kmh'] ?? null) ? (float) $p['speed_kmh'] : null,
                is_numeric($p['heading'] ?? null) ? (int) round((float) $p['heading']) : null,
                is_numeric($p['accuracy_m'] ?? null) ? (int) $p['accuracy_m'] : null,
                $at
            );
            $ok ? $accepted++ : $rejected[$i] = $err;
        }

        return $this->json(['accepted' => $accepted, 'rejected' => (object) $rejected]);
    }
}
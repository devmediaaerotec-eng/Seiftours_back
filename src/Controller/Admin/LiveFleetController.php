<?php
namespace App\Controller\Admin;

use App\Service\SettingsService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/fleet/live')]
#[IsGranted('perm:fleet.track')]
final class LiveFleetController extends AbstractController
{
    private const MOVING_KMH = 5;

    public function __construct(private Connection $db, private SettingsService $settings) {}

    #[Route('', methods: ['GET'])]
    public function live(): JsonResponse
    {
        $offlineAfter = max(2, $this->settings->int('fleet.no_signal_minutes')) * 60;
        $local = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Tunis'));
        $now   = $local->format('Y-m-d H:i:s');
        $soon  = $local->modify('+3 hours')->format('Y-m-d H:i:s');

        $buses = $this->db->fetchAllAssociative(
            "SELECT b.id, b.code, b.plate_number, b.bus_type, b.seats, b.status, b.image_url,
                    p.lat, p.lng, p.speed_kmh, p.heading, p.recorded_at,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, p.recorded_at, UTC_TIMESTAMP(3))) AS age_seconds,
                    ad.id AS assigned_id, CONCAT(ad.first_name, ' ', ad.last_name) AS assigned_name,
                    ad.phone AS assigned_phone, ad.photo_url AS assigned_photo
             FROM buses b
             LEFT JOIN bus_last_position p ON p.bus_id = b.id
             LEFT JOIN drivers ad ON ad.assigned_bus_id = b.id AND ad.is_active = 1
             WHERE b.is_active = 1 ORDER BY b.code"
        );

        // Mission affichée pour chaque bus : celle en cours, sinon celle qui démarre dans les 3 heures
        $missions = $this->db->fetchAllAssociative(
            "SELECT m.id, m.reference, m.title, m.status, m.bus_id, m.starts_at, m.ends_at, m.pickup_place, m.destination, m.pax,
                    d.id AS driver_id, CONCAT(d.first_name, ' ', d.last_name) AS driver_name, d.phone AS driver_phone, d.photo_url AS driver_photo
             FROM bus_missions m JOIN drivers d ON d.id = m.driver_id
             WHERE m.status = 'in_progress' OR (m.status = 'planned' AND m.starts_at <= ? AND m.ends_at > ?)
             ORDER BY FIELD(m.status, 'in_progress', 'planned'), m.starts_at",
            [$soon, $now]
        );
        $missionByBus = [];
        foreach ($missions as $m) {
            $missionByBus[(int) $m['bus_id']] ??= $m;
        }

        $alertRows = $this->db->fetchAllAssociative(
            "SELECT bus_id, COUNT(*) AS total, COALESCE(SUM(severity = 'critical'), 0) AS critical
             FROM fleet_alerts WHERE status IN ('open','acknowledged') AND bus_id IS NOT NULL GROUP BY bus_id"
        );
        $alertByBus = array_column($alertRows, null, 'bus_id');

        $summary = ['total' => 0, 'moving' => 0, 'idle' => 0, 'alert' => 0, 'offline' => 0, 'no_position' => 0];
        $out = [];
        foreach ($buses as $b) {
            $id    = (int) $b['id'];
            $has   = $b['lat'] !== null;
            $age   = $has ? (int) $b['age_seconds'] : null;
            $speed = $b['speed_kmh'] !== null ? (float) $b['speed_kmh'] : null;
            $stale = $has && $age > $offlineAfter;
            $alerts = $alertByBus[$id] ?? null;

            if (!$has)                                        { $state = 'no_position'; }
            elseif ($alerts && (int) $alerts['critical'] > 0) { $state = 'alert'; }
            elseif ($stale)                                   { $state = 'offline'; }
            elseif (($speed ?? 0) >= self::MOVING_KMH)        { $state = 'moving'; }
            else                                              { $state = 'idle'; }

            $m = $missionByBus[$id] ?? null;
            $driver = $m
                ? ['id' => (int) $m['driver_id'], 'name' => $m['driver_name'], 'phone' => $m['driver_phone'], 'photo' => $m['driver_photo']]
                : ($b['assigned_name'] ? ['id' => (int) $b['assigned_id'], 'name' => $b['assigned_name'], 'phone' => $b['assigned_phone'], 'photo' => $b['assigned_photo']] : null);

            $summary['total']++;
            $summary[$state]++;
            $out[] = [
                'id' => $id, 'code' => $b['code'], 'plate_number' => $b['plate_number'], 'bus_type' => $b['bus_type'],
                'seats' => (int) $b['seats'], 'status' => $b['status'], 'image_url' => $b['image_url'],
                'lat' => $has ? (float) $b['lat'] : null, 'lng' => $has ? (float) $b['lng'] : null,
                'speed_kmh' => $speed, 'heading' => $b['heading'] !== null ? (int) $b['heading'] : null,
                'recorded_at' => $b['recorded_at'], 'age_seconds' => $age, 'stale' => $stale, 'state' => $state,
                'driver' => $driver,
                'mission' => $m ? [
                    'id' => (int) $m['id'], 'reference' => $m['reference'], 'title' => $m['title'], 'status' => $m['status'],
                    'starts_at' => $m['starts_at'], 'ends_at' => $m['ends_at'],
                    'pickup_place' => $m['pickup_place'], 'destination' => $m['destination'], 'pax' => $m['pax'] === null ? null : (int) $m['pax'],
                ] : null,
                'alerts' => ['total' => (int) ($alerts['total'] ?? 0), 'critical' => (int) ($alerts['critical'] ?? 0)],
            ];
        }

        return $this->json(['server_time' => gmdate('c'), 'summary' => $summary, 'buses' => $out]);
    }

    /** Trajet parcouru par un bus sur les dernières heures. */
    #[Route('/{busId}/track', methods: ['GET'], requirements: ['busId' => '\d+'])]
    public function track(int $busId, Request $r): JsonResponse
    {
        $hours = max(1, min(48, $r->query->getInt('hours', 6)));
        $rows = $this->db->fetchAllAssociative(
            'SELECT lat, lng, speed_kmh, recorded_at FROM (
                 SELECT lat, lng, speed_kmh, recorded_at FROM vehicle_positions
                 WHERE bus_id = ? AND recorded_at >= UTC_TIMESTAMP(3) - INTERVAL ' . $hours . ' HOUR
                 ORDER BY recorded_at DESC LIMIT 3000
             ) t ORDER BY recorded_at',
            [$busId]
        );

        return $this->json(array_map(static fn (array $p) => [
            'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
            'speed' => $p['speed_kmh'] === null ? null : (float) $p['speed_kmh'], 'at' => $p['recorded_at'],
        ], $rows));
    }
}
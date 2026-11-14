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
    private const MOVING_KMH      = 5;
    private const SPEED_LIMIT_KMH = 90;  // à adapter (ou à déplacer dans les paramètres)
    private const GAP_MAX_S       = 300; // un trou de données plus long n'est pas compté comme durée
    private const MIN_MOVE_M      = 15;  // en dessous : bruit GPS
    private const MAX_PLAUSIBLE_KMH = 160;

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
                    p.lat, p.lng, p.speed_kmh, p.heading, p.ignition, p.odometer_km, p.recorded_at,
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

        $summary = ['total' => 0, 'moving' => 0, 'speeding' => 0, 'idle' => 0, 'parked' => 0,
                    'alert' => 0, 'offline' => 0, 'no_position' => 0, 'anomalies' => 0];
        $out = [];
        foreach ($buses as $b) {
            $id    = (int) $b['id'];
            $has   = $b['lat'] !== null;
            $age   = $has ? (int) $b['age_seconds'] : null;
            $speed = $b['speed_kmh'] !== null ? (float) $b['speed_kmh'] : null;
            $ign   = $b['ignition'] === null ? null : (bool) $b['ignition'];
            $stale = $has && $age > $offlineAfter;
            $alerts = $alertByBus[$id] ?? null;
            $m = $missionByBus[$id] ?? null;

            if (!$has)                                        { $state = 'no_position'; }
            elseif ($alerts && (int) $alerts['critical'] > 0) { $state = 'alert'; }
            elseif ($ign === false)                           { $state = 'parked'; }   // contact coupé : garé, même si le boîtier se tait
            elseif ($stale)                                   { $state = 'offline'; }  // contact allumé mais plus de données
            elseif (($speed ?? 0) > self::SPEED_LIMIT_KMH)    { $state = 'speeding'; }
            elseif (($speed ?? 0) >= self::MOVING_KMH)        { $state = 'moving'; }
            else                                              { $state = 'idle'; }     // moteur allumé, à l'arrêt

            $anomalies = [];
            if ($state === 'speeding') {
                $anomalies[] = ['type' => 'speeding',
                    'label' => sprintf('Excès de vitesse : %d km/h (limite %d)', round($speed), self::SPEED_LIMIT_KMH)];
            }
            if ($state === 'offline') {
                $anomalies[] = ['type' => 'signal', 'label' => 'Signal perdu depuis ' . $this->since($age) . ' (moteur allumé)'];
            }
            if (in_array($state, ['moving', 'speeding'], true) && !$m) {
                $anomalies[] = ['type' => 'no_mission', 'label' => 'Roule sans mission planifiée'];
            }
            if ($m && $m['status'] === 'in_progress' && $state === 'parked') {
                $anomalies[] = ['type' => 'mission_parked', 'label' => 'Mission en cours mais bus garé (moteur coupé)'];
            }

            $driver = $m
                ? ['id' => (int) $m['driver_id'], 'name' => $m['driver_name'], 'phone' => $m['driver_phone'], 'photo' => $m['driver_photo']]
                : ($b['assigned_name'] ? ['id' => (int) $b['assigned_id'], 'name' => $b['assigned_name'], 'phone' => $b['assigned_phone'], 'photo' => $b['assigned_photo']] : null);

            $summary['total']++;
            $summary[$state]++;
            $summary['anomalies'] += count($anomalies);
            $out[] = [
                'id' => $id, 'code' => $b['code'], 'plate_number' => $b['plate_number'], 'bus_type' => $b['bus_type'],
                'seats' => (int) $b['seats'], 'status' => $b['status'], 'image_url' => $b['image_url'],
                'lat' => $has ? (float) $b['lat'] : null, 'lng' => $has ? (float) $b['lng'] : null,
                'speed_kmh' => $speed, 'heading' => $b['heading'] !== null ? (int) $b['heading'] : null,
                'ignition' => $ign, 'odometer_km' => $b['odometer_km'] !== null ? (float) $b['odometer_km'] : null,
                'speed_limit_kmh' => self::SPEED_LIMIT_KMH,
                'recorded_at' => $b['recorded_at'], 'age_seconds' => $age, 'stale' => $stale, 'state' => $state,
                'anomalies' => $anomalies,
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

    /** Statistiques du jour (heure de Tunis) pour toute la flotte. */
    #[Route('/stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $from = $this->todayStartUtc();
        $rows = $this->db->fetchAllAssociative(
            'SELECT bus_id, lat, lng, speed_kmh, ignition, recorded_at FROM vehicle_positions
             WHERE recorded_at >= ? ORDER BY bus_id, recorded_at',
            [$from->format('Y-m-d H:i:s')]
        );
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['bus_id']][] = $r;
        }

        $buses = [];
        $totals = ['distance_km' => 0.0, 'speeding_count' => 0, 'moving_minutes' => 0];
        foreach ($by as $id => $list) {
            $s = $this->aggregate($list);
            $buses[$id] = $s;
            $totals['distance_km']    += $s['distance_km'];
            $totals['speeding_count'] += $s['speeding_count'];
            $totals['moving_minutes'] += $s['moving_minutes'];
        }
        $totals['distance_km'] = round($totals['distance_km'], 1);

        return $this->json(['since' => $from->format('c'), 'totals' => $totals, 'buses' => (object) $buses]);
    }

    /** Détail d'un bus : aujourd'hui, et depuis le début de la mission en cours. */
    #[Route('/{busId}/stats', methods: ['GET'], requirements: ['busId' => '\d+'])]
    public function busStats(int $busId): JsonResponse
    {
        $today = $this->aggregate($this->positionsSince($busId, $this->todayStartUtc()));

        $mission = null;
        $m = $this->db->fetchAssociative(
            "SELECT id, starts_at FROM bus_missions WHERE bus_id = ? AND status = 'in_progress' ORDER BY starts_at DESC LIMIT 1",
            [$busId]
        );
        if ($m) {
            $from = (new \DateTimeImmutable($m['starts_at'], new \DateTimeZone('Africa/Tunis')))->setTimezone(new \DateTimeZone('UTC'));
            $mission = $this->aggregate($this->positionsSince($busId, $from)) + ['mission_id' => (int) $m['id']];
        }

        return $this->json(['today' => $today, 'mission' => $mission]);
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

    // ------------------------------------------------------------------ interne

    private function todayStartUtc(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Africa/Tunis')))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function positionsSince(int $busId, \DateTimeImmutable $fromUtc): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT lat, lng, speed_kmh, ignition, recorded_at FROM vehicle_positions
             WHERE bus_id = ? AND recorded_at >= ? ORDER BY recorded_at LIMIT 20000',
            [$busId, $fromUtc->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Calcule distance, durées et vitesses à partir de positions triées par date (UTC).
     * @param array<int, array<string, mixed>> $rows
     */
    private function aggregate(array $rows): array
    {
        $dist = 0.0;
        $moving = $idle = $speeding = 0;
        $max = 0.0;
        $over = false;
        $firstMove = null;
        $prev = null;

        foreach ($rows as $p) {
            $lat   = (float) $p['lat'];
            $lng   = (float) $p['lng'];
            $speed = $p['speed_kmh'] === null ? null : (float) $p['speed_kmh'];
            $t     = strtotime(substr((string) $p['recorded_at'], 0, 19) . ' UTC');

            if ($speed !== null) {
                $max = max($max, $speed);
                $isOver = $speed > self::SPEED_LIMIT_KMH;
                if ($isOver && !$over) {
                    $speeding++;             // un événement = passage au-dessus de la limite
                }
                $over = $isOver;
                if ($firstMove === null && $speed >= self::MOVING_KMH) {
                    $firstMove = $p['recorded_at'];
                }
            }

            if ($prev) {
                $dt = $t - $prev['t'];
                if ($dt > 0) {
                    $m = $this->haversine($prev['lat'], $prev['lng'], $lat, $lng);
                    if ($m >= self::MIN_MOVE_M && ($m / 1000) / ($dt / 3600) <= self::MAX_PLAUSIBLE_KMH) {
                        $dist += $m;
                    }
                    $seg = min($dt, self::GAP_MAX_S);
                    if (($prev['speed'] ?? 0) >= self::MOVING_KMH) {
                        $moving += $seg;
                    } elseif ($prev['ign'] === true) {
                        $idle += $seg;       // moteur allumé, à l'arrêt = ralenti
                    }
                }
            }
            $prev = ['t' => $t, 'lat' => $lat, 'lng' => $lng, 'speed' => $speed,
                     'ign' => $p['ignition'] === null ? null : (bool) $p['ignition']];
        }

        $km = $dist / 1000;
        $avg = $moving > 0 ? min($max, $km / ($moving / 3600)) : null;

        return [
            'distance_km'    => round($km, 1),
            'max_speed_kmh'  => (int) round($max),
            'avg_speed_kmh'  => $avg === null ? null : (int) round($avg),
            'moving_minutes' => intdiv($moving, 60),
            'idle_minutes'   => intdiv($idle, 60),
            'speeding_count' => $speeding,
            'first_move_at'  => $firstMove,
            'points'         => count($rows),
        ];
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = deg2rad($lat2 - $lat1);
        $b = deg2rad($lng2 - $lng1);
        $h = sin($a / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($b / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($h)));
    }

    private function since(int $s): string
    {
        return $s < 3600 ? max(1, intdiv($s, 60)) . ' min' : ($s < 86400 ? intdiv($s, 3600) . ' h' : intdiv($s, 86400) . ' j');
    }
}
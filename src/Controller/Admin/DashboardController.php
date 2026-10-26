<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Security\PermissionChecker;
use App\Service\AlertEngine;
use App\Service\DocumentStatus;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/admin/dashboard', methods: ['GET'])]
final class DashboardController extends AbstractController
{
    public function __construct(
        private Connection $db,
        private PermissionChecker $perms,
        private AlertEngine $engine,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(#[CurrentUser] StaffUser $user): JsonResponse
    {
        $tz = new \DateTimeZone('Africa/Tunis');
        $now = new \DateTimeImmutable('now', $tz);
        $monthStart = new \DateTimeImmutable($now->format('Y-m-01') . ' 00:00:00', $tz);
        $prevStart = $monthStart->modify('-1 month');
        $prevPoint = min($prevStart->getTimestamp() + ($now->getTimestamp() - $monthStart->getTimestamp()), $monthStart->getTimestamp());
        $seriesFrom = $now->modify('-29 days')->format('Y-m-d');

        $c = [
            'today'          => $now->format('Y-m-d'),
            'tomorrow'       => $now->modify('+1 day')->format('Y-m-d'),
            'now'            => $now->format('Y-m-d H:i:s'),
            'nowUtc'         => $this->utc($now),
            'monthUtc'       => $this->utc($monthStart),
            'prevUtc'        => $this->utc($prevStart),
            'prevPointUtc'   => gmdate('Y-m-d H:i:s', $prevPoint),
            'seriesFrom'     => $seriesFrom,
            'seriesFromUtc'  => $this->utc(new \DateTimeImmutable("$seriesFrom 00:00:00", $tz)),
            'branchId'       => $user->getBranch()?->getId(),
            'branchCode'     => $user->getBranch()?->getCode(),
        ];

        try {
            $this->engine->run(false);
        } catch (\Throwable $e) {
            $this->logger->error('Alert engine failed: ' . $e->getMessage());
        }

        $has = fn (string $p) => $this->perms->has($user, $p);
        $unavailable = [];
        $out = ['generated_at' => gmdate('c'), 'today' => $c['today']];

        if ($has('bookings.read')) {
            $out['bookings'] = $this->safe('bookings', $unavailable, fn () => $this->bookings($c));
            $out['requests'] = $this->safe('requests', $unavailable, fn () => $this->requests($c));
        }
        if ($has('fleet.read')) {
            $out['fleet'] = $this->safe('fleet', $unavailable, fn () => $this->fleet($c));
        }
        if ($has('alerts.read')) {
            $out['alerts'] = $this->safe('alerts', $unavailable, fn () => $this->alerts());
        }
        if ($has('trips.manage')) {
            $out['trips'] = $this->safe('trips', $unavailable, fn () => $this->trips($c));
        }
        if ($has('deals.manage')) {
            $out['deals'] = $this->safe('deals', $unavailable, fn () => $this->deals($c));
        }
        if ($has('audit.read')) {
            $out['activity'] = $this->safe('activity', $unavailable, fn () => $this->activity());
        }

        $out['todo'] = $this->todo($out);
        $out['content'] = $this->content($out);
        $out['unavailable'] = $unavailable;

        return $this->json($out);
    }

    private function utc(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function safe(string $name, array &$unavailable, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->logger->error("Dashboard section $name failed: " . $e->getMessage());
            $unavailable[] = $name;
            return null;
        }
    }

    private function bookings(array $c): array
    {
        $bw = $c['branchId'] ? ' AND b.branch_id = ?' : '';
        $bp = $c['branchId'] ? [$c['branchId']] : [];

        $byStatus = array_map('intval', $this->db->fetchAllKeyValue("SELECT b.status, COUNT(*) FROM bookings b WHERE 1=1$bw GROUP BY b.status", $bp));

        $sales = fn (string $from, string $to): int => (int) $this->db->fetchOne(
            "SELECT COALESCE(SUM(b.total_millimes), 0) FROM bookings b
             WHERE b.status IN ('paid','confirming','confirmed') AND b.created_at >= ? AND b.created_at <= ?$bw", [$from, $to, ...$bp]);
        $cash = fn (string $from, string $to): int => (int) $this->db->fetchOne(
            "SELECT COALESCE(SUM(p.amount_millimes), 0) FROM payments p JOIN bookings b ON b.id = p.booking_id
             WHERE p.status = 'succeeded' AND p.paid_at >= ? AND p.paid_at <= ?$bw", [$from, $to, ...$bp]);
        $delta = fn (int $cur, int $prev): ?float => $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;

        $salesNow = $sales($c['monthUtc'], $c['nowUtc']);
        $salesPrev = $sales($c['prevUtc'], $c['prevPointUtc']);
        $cashNow = $cash($c['monthUtc'], $c['nowUtc']);
        $cashPrev = $cash($c['prevUtc'], $c['prevPointUtc']);

        $todayStartUtc = $this->utc(new \DateTimeImmutable($c['today'] . ' 00:00:00', new \DateTimeZone('Africa/Tunis')));
        $newToday = (int) $this->db->fetchOne("SELECT COUNT(*) FROM bookings b WHERE b.created_at >= ?$bw", [$todayStartUtc, ...$bp]);

        $rows = $this->db->fetchAllAssociative(
            "SELECT DATE(CONVERT_TZ(b.created_at, '+00:00', '+01:00')) AS d, COUNT(*) AS c, COALESCE(SUM(b.total_millimes), 0) AS amount
             FROM bookings b
             WHERE b.created_at >= ? AND b.status NOT IN ('cancelled','expired','failed','payment_failed')$bw GROUP BY d",
            [$c['seriesFromUtc'], ...$bp]);
        $byDay = array_column($rows, null, 'd');
        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $d = (new \DateTimeImmutable($c['seriesFrom']))->modify("+$i days")->format('Y-m-d');
            $series[] = ['date' => $d, 'count' => (int) ($byDay[$d]['c'] ?? 0), 'amount' => (int) ($byDay[$d]['amount'] ?? 0)];
        }

        $latest = $this->db->fetchAllAssociative(
            'SELECT id, reference, status, customer_name, hotel_name, total_millimes, created_at FROM v_bookings_admin'
            . ($c['branchCode'] ? ' WHERE branch_code = ?' : '') . ' ORDER BY created_at DESC LIMIT 6',
            $c['branchCode'] ? [$c['branchCode']] : []);

        return [
            'by_status' => $byStatus, 'pending' => $byStatus['pending_agency'] ?? 0, 'new_today' => $newToday,
            'sales_month' => $salesNow, 'sales_prev' => $salesPrev, 'sales_delta_pct' => $delta($salesNow, $salesPrev),
            'cash_month' => $cashNow, 'cash_prev' => $cashPrev, 'cash_delta_pct' => $delta($cashNow, $cashPrev),
            'series' => $series, 'latest' => $latest,
        ];
    }

    private function requests(array $c): array
    {
        $scope = $c['branchId'] ? ' AND (r.branch_id = ? OR r.branch_id IS NULL)' : '';
        $sp = $c['branchId'] ? [$c['branchId']] : [];

        $messages = $this->db->fetchAllAssociative(
            "SELECT id, name, phone, email, subject, message, created_at FROM contact_messages WHERE status = 'new' ORDER BY created_at DESC LIMIT 5");
        foreach ($messages as &$m) {
            $m['message'] = mb_strimwidth((string) $m['message'], 0, 110, '…');
        }
        unset($m);

        return [
            'new_requests' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM trip_requests r WHERE r.status = 'new'$scope", $sp),
            'latest_requests' => $this->db->fetchAllAssociative(
                "SELECT r.id, r.reference, r.client_name, r.client_phone, r.adults, r.children, r.created_at, t.title AS trip_title
                 FROM trip_requests r JOIN trips t ON t.id = r.trip_id WHERE r.status = 'new'$scope ORDER BY r.created_at DESC LIMIT 5", $sp),
            'new_messages' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'"),
            'latest_messages' => $messages,
        ];
    }

    private function fleet(array $c): array
    {
        $limit = (new \DateTimeImmutable($c['today']))->modify('+' . DocumentStatus::SOON_DAYS . ' days')->format('Y-m-d');

        $buses = array_map('intval', $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'available'), 0) AS available, COALESCE(SUM(status = 'on_mission'), 0) AS on_mission,
                    COALESCE(SUM(status IN ('maintenance','out_of_service')), 0) AS unavailable FROM buses WHERE is_active = 1"));
        $drivers = array_map('intval', $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'active'), 0) AS active, COALESCE(SUM(assigned_bus_id IS NULL), 0) AS without_bus
             FROM drivers WHERE is_active = 1"));

        $today = array_map('intval', $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status IN ('planned','in_progress') AND ends_at < ?), 0) AS to_control,
                    COALESCE(SUM(status = 'completed'), 0) AS completed
             FROM bus_missions WHERE starts_at < ? AND ends_at > ?",
            [$c['now'], $c['tomorrow'] . ' 00:00:00', $c['today'] . ' 00:00:00']));

        $missions = $this->db->fetchAllAssociative(
            "SELECT m.id, m.reference, m.title, m.starts_at, m.ends_at, m.status, m.pax, b.code AS bus_code,
                    CONCAT(d.first_name, ' ', d.last_name) AS driver_name
             FROM bus_missions m JOIN buses b ON b.id = m.bus_id JOIN drivers d ON d.id = m.driver_id
             WHERE m.starts_at < ? AND m.ends_at > ? ORDER BY m.starts_at LIMIT 12",
            [$c['tomorrow'] . ' 00:00:00', $c['today'] . ' 00:00:00']);

        return [
            'buses' => $buses, 'drivers' => $drivers, 'today' => $today, 'missions' => $missions,
            'to_control' => (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM bus_missions WHERE status IN ('planned','in_progress') AND ends_at < ?", [$c['now']]),
            'unsent' => (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM bus_missions WHERE status = 'planned' AND starts_at > ? AND starts_at <= ?
                   AND (notified_at IS NULL OR planning_updated_at > notified_at)",
                [$c['now'], (new \DateTimeImmutable($c['now']))->modify('+48 hours')->format('Y-m-d H:i:s')]),
            'docs_buses' => (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM buses WHERE is_active = 1 AND ((insurance_expires_on IS NOT NULL AND insurance_expires_on <= ?)
                   OR (inspection_expires_on IS NOT NULL AND inspection_expires_on <= ?))', [$limit, $limit]),
            'docs_drivers' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM drivers WHERE is_active = 1 AND license_expires_on <= ?', [$limit]),
        ];
    }

    private function alerts(): array
    {
        $counts = array_map('intval', $this->db->fetchAssociative(
            "SELECT COALESCE(SUM(status = 'open'), 0) AS open, COALESCE(SUM(status = 'acknowledged'), 0) AS acknowledged,
                    COALESCE(SUM(status IN ('open','acknowledged') AND severity = 'critical'), 0) AS critical FROM fleet_alerts"));
        $latest = $this->db->fetchAllAssociative(
            "SELECT id, type, severity, status, message, created_at FROM fleet_alerts WHERE status IN ('open','acknowledged')
             ORDER BY FIELD(severity,'critical','warning','info'), created_at DESC LIMIT 5");

        return ['counts' => $counts, 'latest' => $latest];
    }

    private function trips(array $c): array
    {
        $t = array_map('intval', $this->db->fetchAssociative(
            'SELECT COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS hidden FROM trips'));

        return [
            'active' => $t['active'], 'hidden' => $t['hidden'],
            'no_departure' => (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM trips t WHERE t.is_active = 1 AND t.type IN ('omra','organized')
                   AND NOT EXISTS (SELECT 1 FROM trip_departures x WHERE x.trip_id = t.id AND x.is_active = 1 AND x.departure_date >= ?)", [$c['today']]),
            'no_image' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM trips WHERE is_active = 1 AND (image_url IS NULL OR image_url = '')"),
            'low_seats' => (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM trip_departures x JOIN trips t ON t.id = x.trip_id
                 WHERE x.is_active = 1 AND t.is_active = 1 AND x.departure_date >= ? AND x.seats_left IS NOT NULL AND x.seats_left <= 5', [$c['today']]),
            'upcoming' => $this->db->fetchAllAssociative(
                'SELECT x.id, x.trip_id, t.title, x.departure_date, x.seats_left, x.seats_total, x.price_per_person_millimes
                 FROM trip_departures x JOIN trips t ON t.id = x.trip_id
                 WHERE x.is_active = 1 AND t.is_active = 1 AND x.departure_date >= ? ORDER BY x.departure_date LIMIT 6', [$c['today']]),
        ];
    }

    private function deals(array $c): array
    {
        $soon = (new \DateTimeImmutable($c['today']))->modify('+7 days')->format('Y-m-d');

        return [
            'active' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM deals WHERE is_active = 1 AND (valid_until IS NULL OR valid_until >= ?)', [$c['today']]),
            'expired_published' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM deals WHERE is_active = 1 AND valid_until IS NOT NULL AND valid_until < ?', [$c['today']]),
            'ending_soon' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM deals WHERE is_active = 1 AND valid_until >= ? AND valid_until <= ?', [$c['today'], $soon]),
        ];
    }

    private function activity(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.action, a.entity_type, a.entity_id, a.before_data, a.after_data, a.created_at, s.full_name AS staff_name
             FROM audit_log a LEFT JOIN staff_users s ON s.id = a.staff_id ORDER BY a.id DESC LIMIT 8');

        return array_map(static function (array $r): array {
            $d = json_decode($r['after_data'] ?: ($r['before_data'] ?: 'null'), true);
            $label = null;
            if (is_array($d)) {
                $label = $d['title'] ?? $d['name'] ?? $d['code'] ?? $d['full_name'] ?? $d['email']
                    ?? (isset($d['first_name']) ? trim($d['first_name'] . ' ' . ($d['last_name'] ?? '')) : null);
            }
            return ['action' => $r['action'], 'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'],
                    'label' => is_scalar($label) ? (string) $label : null, 'staff_name' => $r['staff_name'], 'created_at' => $r['created_at']];
        }, $rows);
    }

    // ------------------------------------------------------------- listes à faire

    private function build(array $items): array
    {
        $items = array_values(array_filter($items, static fn (array $i) => $i['count'] > 0));
        $rank = ['bad' => 0, 'warn' => 1, 'info' => 2];
        usort($items, static fn (array $a, array $b) => ($rank[$a['tone']] ?? 9) <=> ($rank[$b['tone']] ?? 9));
        return $items;
    }

    private function item(string $key, string $label, int $count, string $tone, ?string $to): array
    {
        return ['key' => $key, 'label' => $label, 'count' => $count, 'tone' => $tone, 'to' => $to];
    }

    private function todo(array $o): array
    {
        $l = [];
        if (!empty($o['bookings'])) {
            $l[] = $this->item('pending', 'Devis agence en attente de traitement', $o['bookings']['pending'], 'warn', '/bookings?status=pending_agency');
        }
        if (!empty($o['requests'])) {
            $l[] = $this->item('requests', 'Demandes de devis voyage non traitées', $o['requests']['new_requests'], 'warn', null);
            $l[] = $this->item('messages', 'Messages de contact non lus', $o['requests']['new_messages'], 'info', null);
        }
        if (!empty($o['alerts'])) {
            $cr = $o['alerts']['counts']['critical'];
            $l[] = $this->item('critical', 'Alertes critiques en cours', $cr, 'bad', '/fleet/alerts');
            $l[] = $this->item('alerts', 'Autres alertes à traiter', max(0, $o['alerts']['counts']['open'] - $cr), 'warn', '/fleet/alerts');
        }
        if (!empty($o['fleet'])) {
            $f = $o['fleet'];
            $l[] = $this->item('control', 'Missions terminées à contrôler', $f['to_control'], 'warn', '/fleet/missions/list?control=pending');
            $l[] = $this->item('unsent', "Missions des 48 h dont le programme n'est pas envoyé", $f['unsent'], 'warn', '/fleet/missions');
            $l[] = $this->item('docs_buses', 'Bus : assurance ou visite technique à renouveler', $f['docs_buses'], 'warn', '/fleet/buses?docs=expiring');
            $l[] = $this->item('docs_drivers', 'Chauffeurs : permis à renouveler', $f['docs_drivers'], 'warn', '/fleet/drivers?docs=expiring');
            $l[] = $this->item('no_bus', 'Chauffeurs sans bus attribué', $f['drivers']['without_bus'], 'info', '/fleet/drivers?bus=none');
        }
        return $this->build($l);
    }

    private function content(array $o): array
    {
        $l = [];
        if (!empty($o['deals'])) {
            $l[] = $this->item('deals_expired', 'Bons plans expirés encore publiés', $o['deals']['expired_published'], 'bad', '/deals?status=expired');
            $l[] = $this->item('deals_soon', 'Bons plans qui expirent sous 7 jours', $o['deals']['ending_soon'], 'warn', '/deals?status=active');
        }
        if (!empty($o['trips'])) {
            $l[] = $this->item('trips_no_dep', 'Voyages publiés sans départ à venir', $o['trips']['no_departure'], 'warn', '/trips');
            $l[] = $this->item('trips_low', 'Départs presque complets (5 places ou moins)', $o['trips']['low_seats'], 'info', '/trips');
            $l[] = $this->item('trips_no_img', 'Voyages publiés sans photo de couverture', $o['trips']['no_image'], 'info', '/trips');
        }
        return $this->build($l);
    }
}
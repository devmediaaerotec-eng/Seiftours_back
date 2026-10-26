<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\AlertEngine;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/fleet/alerts')]
#[IsGranted('perm:alerts.read')]
final class AlertAdminController extends AbstractController
{
    private const SEVERITIES = ['info', 'warning', 'critical'];
    private const TYPES = ['route_deviation', 'speeding', 'stopped_too_long', 'late_departure', 'no_signal', 'sos',
                           'document_expiring', 'control_overdue', 'program_unsent', 'incident'];
    private const SELECT = "a.id, a.type, a.source, a.severity, a.status, a.message, a.lat, a.lng, a.created_at,
        a.acknowledged_at, a.resolved_at, a.resolution_note,
        a.bus_id, b.code AS bus_code, a.driver_id, CONCAT(d.first_name, ' ', d.last_name) AS driver_name,
        a.mission_id, m.reference AS mission_reference, m.title AS mission_title,
        ak.full_name AS acknowledged_by_name, rs.full_name AS resolved_by_name, cr.full_name AS created_by_name";
    private const FROM = 'fleet_alerts a
        LEFT JOIN buses b ON b.id = a.bus_id
        LEFT JOIN drivers d ON d.id = a.driver_id
        LEFT JOIN bus_missions m ON m.id = a.mission_id
        LEFT JOIN staff_users ak ON ak.id = a.acknowledged_by
        LEFT JOIN staff_users rs ON rs.id = a.resolved_by
        LEFT JOIN staff_users cr ON cr.id = a.created_by';

    public function __construct(private Connection $db, private AlertEngine $engine, private LoggerInterface $logger) {}

    private function runEngine(): void
    {
        try {
            $this->engine->run(false);
        } catch (\Throwable $e) {
            $this->logger->error('Alert engine failed: ' . $e->getMessage());
        }
    }

    private function fetch(int $id): ?array
    {
        return $this->db->fetchAssociative('SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE a.id = ?', [$id]) ?: null;
    }

    private function present(array $a): array
    {
        foreach (['id', 'bus_id', 'driver_id', 'mission_id'] as $k) {
            $a[$k] = $a[$k] === null ? null : (int) $a[$k];
        }
        $a['lat'] = $a['lat'] === null ? null : (float) $a['lat'];
        $a['lng'] = $a['lng'] === null ? null : (float) $a['lng'];
        return $a;
    }

    private function invalid(array $v): JsonResponse
    {
        return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.', 'violations' => $v], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/count', methods: ['GET'])]
    public function count(): JsonResponse
    {
        $this->runEngine();
        $row = $this->db->fetchAssociative(
            "SELECT COALESCE(SUM(status = 'open'), 0) AS open,
                    COALESCE(SUM(status = 'acknowledged'), 0) AS acknowledged,
                    COALESCE(SUM(status IN ('open','acknowledged') AND severity = 'critical'), 0) AS critical,
                    COALESCE(SUM(status = 'resolved' AND resolved_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY), 0) AS resolved_week
             FROM fleet_alerts"
        );
        return $this->json(array_map('intval', $row));
    }

    /** Lance les vérifications immédiatement. */
    #[Route('/run', methods: ['POST'])]
    #[IsGranted('perm:alerts.manage')]
    public function run(): JsonResponse
    {
        return $this->json($this->engine->run(true) ?? ['created' => 0, 'updated' => 0, 'resolved' => 0, 'busy' => true]);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $this->runEngine();
        $where = [];
        $params = [];

        $status = $r->query->getString('status', 'active');
        if (in_array($status, ['open', 'acknowledged', 'resolved'], true)) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        } elseif ($status !== 'all') {
            $where[] = "a.status IN ('open','acknowledged')";
        }
        if (in_array($sev = $r->query->getString('severity'), self::SEVERITIES, true)) {
            $where[] = 'a.severity = ?';
            $params[] = $sev;
        }
        if (in_array($type = $r->query->getString('type'), self::TYPES, true)) {
            $where[] = 'a.type = ?';
            $params[] = $type;
        }
        if (($q = trim($r->query->getString('q'))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(a.message LIKE ? OR b.code LIKE ? OR CONCAT(d.first_name, ' ', d.last_name) LIKE ?)";
            array_push($params, $like, $like, $like);
        }

        $cond   = $where ? implode(' AND ', $where) : '1=1';
        $order  = $status === 'resolved' ? 'a.resolved_at DESC' : "FIELD(a.severity,'critical','warning','info'), a.created_at DESC";
        $limit  = max(1, min(100, $r->query->getInt('limit', 20)));
        $page   = max(1, $r->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::FROM . " WHERE $cond", $params);
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . " WHERE $cond ORDER BY $order LIMIT $limit OFFSET $offset", $params
        );

        return $this->json(['total' => $total, 'page' => $page, 'limit' => $limit, 'items' => array_map(fn (array $a) => $this->present($a), $rows)]);
    }

    /** Signalement manuel d'un incident (panne, accident, appel du chauffeur...). */
    #[Route('', methods: ['POST'])]
    #[IsGranted('perm:alerts.manage')]
    public function create(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $b = json_decode($r->getContent(), true);
        if (!is_array($b)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $errors = [];
        $msg = trim((string) ($b['message'] ?? ''));
        if ($msg === '') {
            $errors['message'] = 'Décrivez la situation.';
        } elseif (mb_strlen($msg) > 255) {
            $errors['message'] = '255 caractères maximum.';
        }
        $sev = $b['severity'] ?? 'warning';
        if (!in_array($sev, self::SEVERITIES, true)) {
            $errors['severity'] = 'Gravité invalide.';
        }
        $ids = [];
        foreach (['bus_id' => 'buses', 'driver_id' => 'drivers', 'mission_id' => 'bus_missions'] as $k => $table) {
            $v = $b[$k] ?? null;
            if ($v === null) {
                $ids[$k] = null;
            } elseif (!is_int($v) || !$this->db->fetchOne("SELECT 1 FROM `$table` WHERE id = ?", [$v])) {
                $errors[$k] = 'Élément introuvable.';
            } else {
                $ids[$k] = $v;
            }
        }
        if ($errors) {
            return $this->invalid($errors);
        }

        $this->db->insert('fleet_alerts', $ids + [
            'type' => 'incident', 'source' => 'manual', 'severity' => $sev, 'message' => $msg,
            'dedup_key' => 'manual:' . bin2hex(random_bytes(6)), 'created_by' => $user->getId(),
        ]);

        return $this->json($this->present($this->fetch((int) $this->db->lastInsertId())), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:alerts.manage')]
    public function act(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $a = $this->fetch($id);
        if (!$a) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $b = json_decode($r->getContent(), true);
        $action = is_array($b) ? ($b['action'] ?? null) : null;
        $note = is_array($b) ? trim((string) ($b['note'] ?? '')) : '';
        if (mb_strlen($note) > 1000) {
            return $this->invalid(['note' => '1000 caractères maximum.']);
        }

        switch ($action) {
            case 'acknowledge':
                if ($a['status'] !== 'open') {
                    return $this->invalid(['action' => "Cette alerte n'est plus à traiter."]);
                }
                $this->db->executeStatement(
                    "UPDATE fleet_alerts SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = UTC_TIMESTAMP(3) WHERE id = ?",
                    [$user->getId(), $id]);
                break;
            case 'resolve':
                if ($a['status'] === 'resolved') {
                    return $this->invalid(['action' => 'Alerte déjà résolue.']);
                }
                $this->db->executeStatement(
                    "UPDATE fleet_alerts SET status = 'resolved', resolved_by = ?, resolved_at = UTC_TIMESTAMP(3), resolution_note = ? WHERE id = ?",
                    [$user->getId(), $note !== '' ? $note : null, $id]);
                break;
            case 'reopen':
                if ($a['status'] !== 'resolved') {
                    return $this->invalid(['action' => "Seule une alerte résolue peut être rouverte."]);
                }
                $this->db->executeStatement(
                    "UPDATE fleet_alerts SET status = 'open', resolved_by = NULL, resolved_at = NULL, resolution_note = NULL,
                            acknowledged_by = NULL, acknowledged_at = NULL WHERE id = ?", [$id]);
                break;
            default:
                return $this->invalid(['action' => 'Action inconnue.']);
        }

        return $this->json($this->present($this->fetch($id)));
    }
}
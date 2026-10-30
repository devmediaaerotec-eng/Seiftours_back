<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\CatalogCrud;
use App\Service\MissionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/fleet/transport-requests')]
#[IsGranted('perm:fleet.read')]
final class TransportRequestAdminController extends AbstractController
{
    private const STATUSES = ['new', 'contacted', 'quoted', 'confirmed', 'declined'];
    private const SELECT = 'r.id, r.reference, r.vehicle_pref, r.purpose, r.pax, r.starts_at, r.ends_at, r.pickup_place, r.destination,
        r.client_name, r.client_phone, r.client_email, r.message, r.status, r.admin_notes, r.quoted_total_millimes,
        r.handled_at, r.created_at, s.full_name AS handled_by_name';
    private const FROM = 'transport_requests r LEFT JOIN staff_users s ON s.id = r.handled_by';

    public function __construct(private Connection $db, private MissionService $missions, private CatalogCrud $crud) {}

    private function fetch(int $id): ?array
    {
        return $this->db->fetchAssociative('SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE r.id = ?', [$id]) ?: null;
    }

    /** Bus libres sur la période demandée (hors maintenance), selon le type souhaité. */
    private function present(array $r, string $now): array
    {
        $r['id'] = (int) $r['id'];
        $r['pax'] = (int) $r['pax'];
        $r['quoted_total_millimes'] = $r['quoted_total_millimes'] === null ? null : (int) $r['quoted_total_millimes'];
        $r['availability'] = null;

        if (in_array($r['status'], ['new', 'contacted', 'quoted'], true) && $r['ends_at'] >= $now) {
            $a = $this->db->fetchAssociative(
                "SELECT COUNT(*) AS buses, COALESCE(SUM(b.seats), 0) AS seats FROM buses b
                 WHERE b.is_active = 1 AND b.status NOT IN ('maintenance','out_of_service')
                   AND (? = 'any' OR b.bus_type = ?)
                   AND NOT EXISTS (SELECT 1 FROM bus_missions m WHERE m.bus_id = b.id
                                   AND m.status IN ('planned','in_progress','completed') AND m.starts_at < ? AND m.ends_at > ?)",
                [$r['vehicle_pref'], $r['vehicle_pref'], $r['ends_at'], $r['starts_at']]
            );
            $r['availability'] = ['buses' => (int) $a['buses'], 'seats' => (int) $a['seats']];
        }
        return $r;
    }

    #[Route('/stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $rows = $this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM transport_requests GROUP BY status');
        $out = ['total' => 0];
        foreach (self::STATUSES as $s) {
            $out[$s] = (int) ($rows[$s] ?? 0);
            $out['total'] += $out[$s];
        }
        return $this->json($out);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $where = ['1=1'];
        $params = [];
        if (in_array($st = $r->query->getString('status'), self::STATUSES, true)) {
            $where[] = 'r.status = ?';
            $params[] = $st;
        }
        if (($q = trim($r->query->getString('q'))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(r.reference LIKE ? OR r.client_name LIKE ? OR r.client_phone LIKE ? OR r.pickup_place LIKE ? OR r.destination LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $limit = max(1, min(100, $r->query->getInt('limit', 20)));
        $page = max(1, $r->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $cond = implode(' AND ', $where);
        $now = $this->missions->nowLocal();

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM transport_requests r WHERE $cond", $params);
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM
            . " WHERE $cond ORDER BY FIELD(r.status,'new','contacted','quoted','confirmed','declined'), r.created_at DESC LIMIT $limit OFFSET $offset",
            $params
        );

        return $this->json([
            'total' => $total, 'page' => $page, 'limit' => $limit,
            'items' => array_map(fn (array $x) => $this->present($x, $now), $rows),
        ]);
    }

    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:missions.manage')]
    public function update(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $before = $this->fetch($id);
        if (!$before) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $b = json_decode($r->getContent(), true);
        if (!is_array($b)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $set = [];
        $errors = [];
        if (array_key_exists('status', $b)) {
            in_array($b['status'], self::STATUSES, true) ? $set['status'] = $b['status'] : $errors['status'] = 'Statut invalide.';
        }
        if (array_key_exists('admin_notes', $b)) {
            $n = trim((string) $b['admin_notes']);
            mb_strlen($n) > 5000 ? $errors['admin_notes'] = '5000 caractères maximum.' : $set['admin_notes'] = $n !== '' ? $n : null;
        }
        if (array_key_exists('quoted_total_millimes', $b)) {
            $q = $b['quoted_total_millimes'];
            if ($q === null) {
                $set['quoted_total_millimes'] = null;
            } elseif (is_int($q) && $q >= 0) {
                $set['quoted_total_millimes'] = $q;
            } else {
                $errors['quoted_total_millimes'] = 'Montant invalide.';
            }
        }
        if ($errors) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.', 'violations' => $errors],
                Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$set) {
            return $this->json(['error' => 'nothing_to_update'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($set['status']) && $set['status'] !== $before['status']) {
            $set['handled_by'] = $user->getId();
            $set['handled_at'] = gmdate('Y-m-d H:i:s.v');
        }
        $this->db->update('transport_requests', $set, ['id' => $id]);
        $this->crud->audit($user, $r->getClientIp(), 'transport_request.update', 'transport_request', $id,
            ['status' => $before['status']], $set);

        return $this->json($this->present($this->fetch($id), $this->missions->nowLocal()));
    }
}
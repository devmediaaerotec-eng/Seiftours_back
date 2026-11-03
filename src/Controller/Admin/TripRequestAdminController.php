<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Demandes de réservation reçues du site (voyages organisés, Omra, circuits). */
#[Route('/api/admin/trip-requests')]
#[IsGranted('perm:trips.manage')]
final class TripRequestAdminController extends AbstractController
{
    private const STATUSES = ['new', 'contacted', 'confirmed', 'cancelled'];
    private const TYPES = ['omra', 'organized', 'circuit'];

    public function __construct(private Connection $db) {}

    private const SELECT = "SELECT q.*, t.title AS trip_title, t.type AS trip_type, t.slug AS trip_slug,
                                   dep.departure_date, s.full_name AS handled_by_name
                            FROM trip_requests q
                            JOIN trips t ON t.id = q.trip_id
                            LEFT JOIN trip_departures dep ON dep.id = q.departure_id
                            LEFT JOIN staff_users s ON s.id = q.handled_by";

    /** Compteurs pour le badge du menu et les onglets */
    #[Route('/counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM trip_requests GROUP BY status') as $row) {
            $out[$row['status']] = (int) $row['n'];
        }

        return $this->json($out);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $where = ['1=1'];
        $p = [];
        $status = $r->query->getString('status');
        if (in_array($status, self::STATUSES, true)) { $where[] = 'q.status = ?'; $p[] = $status; }
        $type = $r->query->getString('type');
        if (in_array($type, self::TYPES, true)) { $where[] = 't.type = ?'; $p[] = $type; }
        if (($s = trim($r->query->getString('q'))) !== '') {
            $like = '%' . addcslashes($s, '%_\\') . '%';
            $where[] = '(q.client_name LIKE ? OR q.client_phone LIKE ? OR q.client_email LIKE ? OR q.reference LIKE ? OR t.title LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like);
        }
        $cond = implode(' AND ', $where);
        $limit = max(1, min(100, $r->query->getInt('limit', 20)));
        $page = max(1, $r->query->getInt('page', 1));

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM trip_requests q JOIN trips t ON t.id = q.trip_id WHERE $cond", $p);
        $items = $this->db->fetchAllAssociative(
            self::SELECT . " WHERE $cond ORDER BY (q.status = 'new') DESC, q.created_at DESC LIMIT $limit OFFSET " . (($page - 1) * $limit), $p);

        return $this->json(['total' => $total, 'page' => $page, 'limit' => $limit, 'items' => $items]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $row = $this->db->fetchAssociative(self::SELECT . ' WHERE q.id = ?', [$id]);

        return $row ? $this->json($row) : $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    /** Statut, devis envoyé et notes internes */
    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $before = $this->db->fetchAssociative('SELECT status, quoted_total_millimes, admin_notes FROM trip_requests WHERE id = ?', [$id]);
        if (!$before) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $in = json_decode($r->getContent(), true);
        if (!is_array($in)) {
            return $this->json(['error' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }

        $set = [];
        $err = [];
        if (array_key_exists('status', $in)) {
            in_array($in['status'], self::STATUSES, true) ? $set['status'] = $in['status'] : $err['status'] = 'Statut inconnu.';
        }
        if (array_key_exists('quoted_total_millimes', $in)) {
            $v = $in['quoted_total_millimes'];
            if ($v === null || $v === '') { $set['quoted_total_millimes'] = null; }
            elseif (is_numeric($v) && (int) $v > 0) { $set['quoted_total_millimes'] = (int) $v; }
            else { $err['quoted_total_millimes'] = 'Montant invalide.'; }
        }
        if (array_key_exists('admin_notes', $in)) {
            $set['admin_notes'] = ($n = trim((string) $in['admin_notes'])) === '' ? null : mb_substr($n, 0, 5000);
        }
        if ($err) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Champs invalides.', 'violations' => $err], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($set) {
            $set['handled_by'] = $user->getId();
            $this->db->update('trip_requests', $set + ['handled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v')], ['id' => $id]);
            $this->db->insert('audit_log', [
                'staff_id' => $user->getId(), 'action' => 'update', 'entity_type' => 'trip_request', 'entity_id' => (string) $id,
                'before_data' => json_encode($before, JSON_UNESCAPED_UNICODE), 'after_data' => json_encode($set, JSON_UNESCAPED_UNICODE),
                'ip' => $r->getClientIp(),
            ]);
        }

        return $this->show($id);
    }
}

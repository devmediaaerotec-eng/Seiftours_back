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

/** Boîte de réception des messages du formulaire de contact. */
#[Route('/api/admin/contact-messages')]
#[IsGranted('perm:contacts.read')]
final class ContactMessageAdminController extends AbstractController
{
    private const STATUSES = ['new', 'read', 'answered', 'archived'];
    private const SUBJECTS = ['general', 'hotels', 'omra', 'trips', 'flights', 'circuits', 'transport'];

    private const SELECT = 'SELECT m.*, s.full_name AS handled_by_name FROM contact_messages m LEFT JOIN staff_users s ON s.id = m.handled_by';

    public function __construct(private Connection $db) {}

    #[Route('/counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->fetchAllAssociative('SELECT status, COUNT(*) AS n FROM contact_messages GROUP BY status') as $row) {
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
        if (in_array($status, self::STATUSES, true)) { $where[] = 'm.status = ?'; $p[] = $status; }
        else { $where[] = "m.status <> 'archived'"; }                    // l'archive ne s'affiche que si on la demande
        $subject = $r->query->getString('subject');
        if (in_array($subject, self::SUBJECTS, true)) { $where[] = 'm.subject = ?'; $p[] = $subject; }
        if (($q = trim($r->query->getString('q'))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(m.name LIKE ? OR m.phone LIKE ? OR m.email LIKE ? OR m.message LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        $cond = implode(' AND ', $where);
        $limit = max(1, min(100, $r->query->getInt('limit', 30)));
        $page = max(1, $r->query->getInt('page', 1));

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM contact_messages m WHERE $cond", $p);
        $items = $this->db->fetchAllAssociative(
            self::SELECT . " WHERE $cond ORDER BY (m.status = 'new') DESC, m.created_at DESC LIMIT $limit OFFSET " . (($page - 1) * $limit), $p);

        return $this->json(['total' => $total, 'page' => $page, 'limit' => $limit, 'items' => array_map(fn ($i) => ['id' => (int) $i['id']] + $i, $items)]);
    }

    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:contacts.manage')]
    public function update(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $before = $this->db->fetchAssociative('SELECT status, admin_notes FROM contact_messages WHERE id = ?', [$id]);
        if (!$before) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $in = json_decode($r->getContent(), true);
        if (!is_array($in)) {
            return $this->json(['error' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }
        $set = [];
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], self::STATUSES, true)) {
                return $this->json(['error' => 'validation_failed', 'message' => 'Statut inconnu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $set['status'] = $in['status'];
        }
        if (array_key_exists('admin_notes', $in)) {
            $set['admin_notes'] = ($n = trim((string) $in['admin_notes'])) === '' ? null : mb_substr($n, 0, 5000);
        }
        if ($set) {
            $this->db->update('contact_messages', $set + ['handled_by' => $user->getId(), 'handled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v')], ['id' => $id]);
            $this->audit($r, $user, 'contact_message.update', $id, $before, $set);
        }

        return $this->json($this->db->fetchAssociative(self::SELECT . ' WHERE m.id = ?', [$id]));
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:contacts.manage')]
    public function delete(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $before = $this->db->fetchAssociative('SELECT * FROM contact_messages WHERE id = ?', [$id]);
        if (!$before) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $this->db->delete('contact_messages', ['id' => $id]);
        $this->audit($r, $user, 'contact_message.delete', $id, $before, null);

        return $this->json(['ok' => true]);
    }

    private function audit(Request $r, StaffUser $u, string $action, int $id, ?array $before, ?array $after): void
    {
        $enc = fn (?array $a) => $a === null ? null : json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->db->insert('audit_log', [
            'staff_id' => $u->getId(), 'action' => $action, 'entity_type' => 'contact_message', 'entity_id' => (string) $id,
            'before_data' => $enc($before), 'after_data' => $enc($after), 'ip' => $r->getClientIp(),
        ]);
    }
}

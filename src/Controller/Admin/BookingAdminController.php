<?php
namespace App\Controller\Admin;

use App\Dto\BookingStatusInput;
use App\Entity\StaffUser;
use App\Security\PermissionChecker;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/bookings')]
final class BookingAdminController extends AbstractController
{
    private const TRANSITIONS = [
        'pending_agency' => ['confirmed', 'cancelled', 'expired'],
        'confirmed'      => ['cancelled'],
    ];

    public function __construct(private Connection $db, private PermissionChecker $perms) {}

    #[Route('', methods: ['GET'])]
    #[IsGranted('perm:bookings.read')]
    public function list(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $where = ['1=1'];
        $params = [];

        if ($user->getBranch() !== null) {
            $where[] = 'branch_code = ?';
            $params[] = $user->getBranch()->getCode();
        } elseif ($r->query->getString('branch') !== '') {
            $where[] = 'branch_code = ?';
            $params[] = $r->query->getString('branch');
        }

        if ($r->query->getString('status') !== '') {
            $where[] = 'status = ?';
            $params[] = $r->query->getString('status');
        }

        if (($q = trim($r->query->getString('q'))) !== '') {
            $where[] = '(reference LIKE ? OR customer_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $limit  = max(1, min(100, $r->query->getInt('limit', 20)));
        $page   = max(1, $r->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $cond   = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM v_bookings_admin WHERE $cond", $params);
        $rows  = $this->db->fetchAllAssociative(
            "SELECT * FROM v_bookings_admin WHERE $cond ORDER BY created_at DESC LIMIT $limit OFFSET $offset",
            $params
        );

        return $this->json(['total' => $total, 'page' => $page, 'limit' => $limit, 'items' => $rows]);
    }

    #[Route('/{reference}', methods: ['GET'], requirements: ['reference' => '[A-Z0-9]{6,20}'])]
    #[IsGranted('perm:bookings.read')]
    public function show(string $reference, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $booking = $this->findAccessible($reference, $user);
        if (!$booking) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $id = $booking['id'];

        $items = $this->db->fetchAllAssociative('SELECT * FROM booking_items WHERE booking_id = ?', [$id]);
        foreach ($items as &$item) {
            unset($item['supplier_offer_token']);
            $item['guests'] = $this->db->fetchAllAssociative(
                'SELECT room_index, is_child, age, title, first_name, last_name
                 FROM booking_guests WHERE booking_item_id = ? ORDER BY room_index, id',
                [$item['id']]
            );
        }
        unset($item);

        return $this->json([
            'booking'  => $booking,
            'items'    => $items,
            'payments' => $this->db->fetchAllAssociative(
                'SELECT id, kind, provider, amount_millimes, status, paid_at, receipt_number
                 FROM payments WHERE booking_id = ? ORDER BY id', [$id]),
            'history'  => $this->db->fetchAllAssociative(
                'SELECT h.from_status, h.to_status, h.created_at, s.full_name AS changed_by
                 FROM booking_status_history h LEFT JOIN staff_users s ON s.id = h.changed_by
                 WHERE h.booking_id = ? ORDER BY h.id', [$id]),
            'notes'    => $this->db->fetchAllAssociative(
                'SELECT n.body, n.created_at, s.full_name AS author
                 FROM booking_notes n JOIN staff_users s ON s.id = n.staff_id
                 WHERE n.booking_id = ? ORDER BY n.id', [$id]),
        ]);
    }

    #[Route('/{reference}/status', methods: ['PATCH'], requirements: ['reference' => '[A-Z0-9]{6,20}'])]
    #[IsGranted('perm:bookings.write')]
    public function changeStatus(
        string $reference,
        #[MapRequestPayload] BookingStatusInput $in,
        #[CurrentUser] StaffUser $user,
    ): JsonResponse {
        $booking = $this->findAccessible($reference, $user);
        if (!$booking) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $from = $booking['status'];
        $to   = $in->status;

        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return $this->json(['error' => 'transition_not_allowed', 'from' => $from, 'to' => $to],
                Response::HTTP_CONFLICT);
        }
        if ($to === 'cancelled' && !$this->perms->has($user, 'bookings.cancel')) {
            return $this->json(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $sets   = ['status = ?'];
        $params = [$to];
        if ($to === 'confirmed') {
            $sets[] = 'confirmed_at = UTC_TIMESTAMP(3)';
        }
        if ($to === 'cancelled') {
            $sets[]   = 'cancelled_at = UTC_TIMESTAMP(3)';
            $sets[]   = 'cancellation_reason = ?';
            $params[] = $in->note;
        }
        array_push($params, $booking['id'], $from);

        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('SET @app_staff_id = ?', [$user->getId()]);
            $updated = $this->db->executeStatement(
                'UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = ? AND status = ?',
                $params
            );
            if ($updated === 0) {
                $this->db->rollBack();
                return $this->json(['error' => 'status_changed_meanwhile'], Response::HTTP_CONFLICT);
            }
            if ($in->note !== null && trim($in->note) !== '') {
                $this->db->executeStatement(
                    'INSERT INTO booking_notes (booking_id, staff_id, body) VALUES (?, ?, ?)',
                    [$booking['id'], $user->getId(), $in->note]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        } finally {
            $this->db->executeStatement('SET @app_staff_id = NULL');
        }

        return $this->json(['reference' => $reference, 'from' => $from, 'to' => $to]);
    }

    private function findAccessible(string $reference, StaffUser $user): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM v_bookings_admin WHERE reference = ?', [$reference]);
        if (!$row) {
            return null;
        }
        $branch = $user->getBranch();
        if ($branch !== null && $row['branch_code'] !== $branch->getCode()) {
            return null;
        }
        return $row;
    }
}
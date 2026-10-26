<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\CatalogCrud;
use App\Service\DocumentStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/fleet/buses')]
#[IsGranted('perm:fleet.read')]
final class BusAdminController extends AbstractController
{
    private const STATUSES = ['available', 'on_mission', 'maintenance', 'out_of_service'];
    private const TYPES    = ['minibus', 'microbus'];

    private const SELECT = "b.id, b.code, b.bus_type, b.plate_number, b.brand, b.model, b.seats, b.year_made,
        b.status, b.insurance_expires_on, b.inspection_expires_on, b.gps_device_id, b.notes, b.image_url,
        b.is_active, b.created_at, b.updated_at,
        d.id AS driver_id, CONCAT(d.first_name, ' ', d.last_name) AS driver_name, d.photo_url AS driver_photo";
    private const FROM = 'buses b LEFT JOIN drivers d ON d.assigned_bus_id = b.id';

    public function __construct(private Connection $db, private CatalogCrud $crud) {}

    private function cfg(): array
    {
        return [
            'table'  => 'buses',
            'entity' => 'bus',
            'fields' => [
                'code' => ['type' => 'string', 'required' => true, 'max' => 30, 'upper' => true, 'unique' => true,
                           'pattern' => '/^[A-Z0-9][A-Z0-9_-]*$/', 'pattern_message' => 'Lettres, chiffres, tirets et _ uniquement.',
                           'unique_message' => 'Ce code existe déjà.'],
                'bus_type'     => ['type' => 'enum', 'values' => self::TYPES, 'required' => true],
                'plate_number' => ['type' => 'string', 'required' => true, 'max' => 20, 'unique' => true,
                                   'unique_message' => 'Cette immatriculation existe déjà.'],
                'brand'        => ['type' => 'string', 'max' => 60],
                'model'        => ['type' => 'string', 'max' => 60],
                'seats'        => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 90],
                'year_made'    => ['type' => 'int', 'min' => 1980, 'max' => (int) date('Y') + 1],
                'status'       => ['type' => 'enum', 'values' => self::STATUSES, 'default' => 'available'],
                'insurance_expires_on'  => ['type' => 'date'],
                'inspection_expires_on' => ['type' => 'date'],
                'gps_device_id' => ['type' => 'string', 'max' => 60, 'unique' => true,
                                    'unique_message' => 'Ce boîtier GPS est déjà affecté à un autre bus.'],
                'notes'     => ['type' => 'text', 'max' => 5000],
                'image_url' => ['type' => 'string', 'max' => 500],
                'is_active' => ['type' => 'bool', 'default' => true],
            ],
        ];
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Africa/Tunis'));
    }

    private function fetch(int $id): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE b.id = ?', [$id]
        ) ?: null;
    }

    private function present(array $r): array
    {
        $today = $this->today();
        $r['is_active']  = (bool) $r['is_active'];
        $r['insurance']  = DocumentStatus::of($r['insurance_expires_on'], $today);
        $r['inspection'] = DocumentStatus::of($r['inspection_expires_on'], $today);
        return $r;
    }

    private function guardStatus(array $row, ?array $existing): array
    {
        $e = [];
        $old = $existing['status'] ?? null;
        if ($row['status'] === 'on_mission' && $old !== 'on_mission') {
            $e['status'] = 'Le statut « En mission » est géré automatiquement par les missions.';
        }
        if ($old === 'on_mission' && $row['status'] !== 'on_mission') {
            $e['status'] = "Ce bus est en mission : terminez d'abord la mission.";
        }
        if ($old === 'on_mission' && empty($row['is_active'])) {
            $e['is_active'] = "Impossible d'archiver un bus en mission.";
        }
        return $e;
    }

    private function invalid(array $violations): JsonResponse
    {
        return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.',
                            'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // ---------------------------------------------------------------- lecture

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $today = $this->today();
        $where = [$r->query->getString('archived') === '1' ? 'b.is_active = 0' : 'b.is_active = 1'];
        $params = [];

        $status = $r->query->getString('status');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'b.status = ?';
            $params[] = $status;
        }
        $type = $r->query->getString('type');
        if (in_array($type, self::TYPES, true)) {
            $where[] = 'b.bus_type = ?';
            $params[] = $type;
        }
        if ($r->query->getString('docs') === 'expiring') {
            $lim = $today->modify('+' . DocumentStatus::SOON_DAYS . ' days')->format('Y-m-d');
            $where[] = '((b.insurance_expires_on IS NOT NULL AND b.insurance_expires_on <= ?)
                      OR (b.inspection_expires_on IS NOT NULL AND b.inspection_expires_on <= ?))';
            array_push($params, $lim, $lim);
        }
        if (($q = trim($r->query->getString('q'))) !== '') {
            $esc = addcslashes($q, '%_\\');
            $where[] = "(b.code LIKE ? OR b.plate_number LIKE ? OR b.brand LIKE ? OR b.model LIKE ?
                        OR b.gps_device_id LIKE ? OR CONCAT(d.first_name, ' ', d.last_name) LIKE ?)";
            array_push($params, '%' . mb_strtoupper($esc) . '%', "%$esc%", "%$esc%", "%$esc%", "%$esc%", "%$esc%");
        }

        $limit  = max(1, min(100, $r->query->getInt('limit', 20)));
        $page   = max(1, $r->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $cond   = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::FROM . " WHERE $cond", $params);
        $rows  = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . " WHERE $cond ORDER BY b.code LIMIT $limit OFFSET $offset",
            $params
        );

        return $this->json([
            'total' => $total, 'page' => $page, 'limit' => $limit,
            'items' => array_map(fn (array $b) => $this->present($b), $rows),
        ]);
    }

    #[Route('/stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $lim = $this->today()->modify('+' . DocumentStatus::SOON_DAYS . ' days')->format('Y-m-d');
        $row = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'available'), 0)      AS available,
                    COALESCE(SUM(status = 'on_mission'), 0)     AS on_mission,
                    COALESCE(SUM(status = 'maintenance'), 0)    AS maintenance,
                    COALESCE(SUM(status = 'out_of_service'), 0) AS out_of_service,
                    COALESCE(SUM(bus_type = 'minibus'), 0)      AS minibus,
                    COALESCE(SUM(bus_type = 'microbus'), 0)     AS microbus,
                    COALESCE(SUM((insurance_expires_on IS NOT NULL AND insurance_expires_on <= ?)
                              OR (inspection_expires_on IS NOT NULL AND inspection_expires_on <= ?)), 0) AS docs
             FROM buses WHERE is_active = 1",
            [$lim, $lim]
        );

        return $this->json(array_map('intval', $row));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $bus = $this->fetch($id);
        return $bus ? $this->json($this->present($bus)) : $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    // --------------------------------------------------------------- écriture

    #[Route('', methods: ['POST'])]
    #[IsGranted('perm:fleet.manage')]
    public function create(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        return $this->save($r, $user, null);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:fleet.manage')]
    public function update(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        return $this->save($r, $user, $id);
    }

    #[Route('/{id}/status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:fleet.manage')]
    public function status(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $bus = $this->fetch($id);
        if (!$bus) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $body = json_decode($r->getContent(), true);
        $new = is_array($body) ? ($body['status'] ?? null) : null;
        if (!in_array($new, self::STATUSES, true)) {
            return $this->invalid(['status' => 'Statut invalide.']);
        }
        if ($errors = $this->guardStatus(['status' => $new, 'is_active' => $bus['is_active']], $bus)) {
            return $this->invalid($errors);
        }
        $this->db->update('buses', ['status' => $new], ['id' => $id]);
        $this->crud->audit($user, $r->getClientIp(), 'bus.status', 'bus', $id, ['status' => $bus['status']], ['status' => $new]);

        return $this->json(['status' => $new]);
    }

    /** Archiver un bus libère son chauffeur. */
    #[Route('/{id}/flags', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:fleet.manage')]
    public function flags(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $bus = $this->fetch($id);
        if (!$bus) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $body = json_decode($r->getContent(), true);
        if (!is_array($body) || !array_key_exists('is_active', $body)) {
            return $this->json(['error' => 'nothing_to_update'], Response::HTTP_BAD_REQUEST);
        }
        $active = (int) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN);
        if (!$active && $bus['status'] === 'on_mission') {
            return $this->invalid(['is_active' => "Impossible d'archiver un bus en mission."]);
        }

        $this->db->beginTransaction();
        try {
            $this->db->update('buses', ['is_active' => $active], ['id' => $id]);
            if (!$active) {
                $this->db->update('drivers', ['assigned_bus_id' => null], ['assigned_bus_id' => $id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->crud->audit($user, $r->getClientIp(), $active ? 'bus.restore' : 'bus.archive', 'bus', $id, null, ['is_active' => $active]);

        return $this->json(['is_active' => (bool) $active]);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:fleet.manage')]
    public function delete(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $bus = $this->fetch($id);
        if (!$bus) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        if ($bus['status'] === 'on_mission') {
            return $this->json(['error' => 'in_use', 'message' => 'Ce bus est en mission.'], Response::HTTP_CONFLICT);
        }
        try {
            $this->db->delete('buses', ['id' => $id]);     // son chauffeur redevient « sans bus » (ON DELETE SET NULL)
        } catch (ForeignKeyConstraintViolationException) {
            return $this->json([
                'error'   => 'in_use',
                'message' => "Ce bus a un historique (missions, positions). Archivez-le au lieu de le supprimer.",
            ], Response::HTTP_CONFLICT);
        }
        $this->crud->audit($user, $r->getClientIp(), 'bus.delete', 'bus', $id, $bus, null);

        return $this->json(['ok' => true]);
    }

    private function save(Request $r, StaffUser $user, ?int $id): JsonResponse
    {
        $before = null;
        if ($id !== null && !($before = $this->fetch($id))) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $body = json_decode($r->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        [$row, $errors] = $this->crud->validate(
            $this->cfg(), $body, $id,
            fn (array $row, ?array $existing): array => $this->guardStatus($row, $existing)
        );
        if ($errors) {
            return $this->invalid($errors);
        }

        $created = $id === null;
        $this->db->beginTransaction();
        try {
            if ($created) {
                $this->db->insert('buses', $row);
                $id = (int) $this->db->lastInsertId();
            } else {
                $this->db->update('buses', $row, ['id' => $id]);
            }
            if (!$row['is_active']) {
                $this->db->update('drivers', ['assigned_bus_id' => null], ['assigned_bus_id' => $id]);
            }
            $this->db->commit();
        } catch (UniqueConstraintViolationException) {
            $this->db->rollBack();
            return $this->invalid(['code' => 'Code, immatriculation ou boîtier GPS déjà utilisé.']);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->crud->audit($user, $r->getClientIp(), $created ? 'bus.create' : 'bus.update', 'bus', $id, $before, $row);

        return $this->json($this->present($this->fetch($id)), $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
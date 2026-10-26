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

#[Route('/api/admin/fleet/drivers')]
#[IsGranted('perm:fleet.read')]
final class DriverAdminController extends AbstractController
{
    private const STATUSES = ['active', 'on_leave', 'suspended'];

    // pin_hash n'est JAMAIS sélectionné : on expose seulement has_pin
    private const SELECT = "d.id, d.first_name, d.last_name, CONCAT(d.first_name, ' ', d.last_name) AS full_name,
        d.phone, d.email, d.photo_url, d.license_number, d.license_expires_on, d.status,
        (d.pin_hash IS NOT NULL) AS has_pin,
        d.assigned_bus_id, b.code AS bus_code, b.plate_number AS bus_plate, b.bus_type AS bus_type, b.status AS bus_status,
        d.is_active, d.created_at, d.updated_at";
    private const FROM = 'drivers d LEFT JOIN buses b ON b.id = d.assigned_bus_id';

    public function __construct(private Connection $db, private CatalogCrud $crud) {}

    private function cfg(): array
    {
        return [
            'table'  => 'drivers',
            'entity' => 'driver',
            'fields' => [
                'first_name' => ['type' => 'string', 'required' => true, 'max' => 100],
                'last_name'  => ['type' => 'string', 'required' => true, 'max' => 100],
                'phone' => ['type' => 'string', 'required' => true, 'max' => 30, 'unique' => true,
                            'pattern' => '/^\+[0-9]{8,15}$/', 'pattern_message' => 'Format international requis, ex. +21698123456.',
                            'unique_message' => 'Ce numéro est déjà utilisé par un autre chauffeur.'],
                'email'     => ['type' => 'string', 'max' => 190, 'email' => true],
                'photo_url' => ['type' => 'string', 'max' => 500],
                'license_number' => ['type' => 'string', 'required' => true, 'max' => 40, 'unique' => true,
                                     'unique_message' => 'Ce numéro de permis existe déjà.'],
                'license_expires_on' => ['type' => 'date', 'required' => true],
                'status'          => ['type' => 'enum', 'values' => self::STATUSES, 'default' => 'active'],
                'assigned_bus_id' => ['type' => 'fk', 'table' => 'buses'],
                'is_active'       => ['type' => 'bool', 'default' => true],
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
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE d.id = ?', [$id]
        ) ?: null;
    }

    private function present(array $r): array
    {
        $r['is_active'] = (bool) $r['is_active'];
        $r['has_pin']   = (bool) $r['has_pin'];
        $r['license']   = DocumentStatus::of($r['license_expires_on'], $this->today());
        return $r;
    }

    private function invalid(array $violations): JsonResponse
    {
        return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.',
                            'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Règles d'attribution d'un bus. */
    private function guardBus(array $row, ?array $existing): array
    {
        if (empty($row['is_active'])) {
            return [];                      // un chauffeur archivé perd son bus (voir save())
        }
        $new = ($row['assigned_bus_id'] ?? null) === null ? null : (int) $row['assigned_bus_id'];
        $old = ($existing['assigned_bus_id'] ?? null) === null ? null : (int) $existing['assigned_bus_id'];
        if ($new === $old) {
            return [];
        }

        if ($old !== null && $this->db->fetchOne('SELECT status FROM buses WHERE id = ?', [$old]) === 'on_mission') {
            return ['assigned_bus_id' => "Ce chauffeur est en mission : on ne peut pas changer son bus."];
        }
        if ($new === null) {
            return [];
        }

        $bus = $this->db->fetchAssociative('SELECT id, is_active, status FROM buses WHERE id = ?', [$new]);
        if (!$bus || !$bus['is_active']) {
            return ['assigned_bus_id' => 'Ce bus est archivé.'];
        }
        if ($bus['status'] === 'on_mission') {
            return ['assigned_bus_id' => 'Ce bus est en mission.'];
        }
        $other = $this->db->fetchOne(
            "SELECT CONCAT(first_name, ' ', last_name) FROM drivers WHERE assigned_bus_id = ? AND id <> ?",
            [$new, $existing['id'] ?? 0]
        );
        if ($other) {
            return ['assigned_bus_id' => "Ce bus est déjà attribué à $other."];
        }
        return [];
    }

    /** Bus actifs et leur chauffeur actuel, pour la liste déroulante du formulaire. */
    #[Route('/form-options', methods: ['GET'])]
    public function formOptions(): JsonResponse
    {
        return $this->json(['buses' => $this->db->fetchAllAssociative(
            "SELECT b.id, b.code, b.plate_number, b.bus_type, b.seats, b.status,
                    d.id AS driver_id, CONCAT(d.first_name, ' ', d.last_name) AS driver_name
             FROM buses b LEFT JOIN drivers d ON d.assigned_bus_id = b.id
             WHERE b.is_active = 1 ORDER BY b.code"
        )]);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $where = [$r->query->getString('archived') === '1' ? 'd.is_active = 0' : 'd.is_active = 1'];
        $params = [];

        $status = $r->query->getString('status');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'd.status = ?';
            $params[] = $status;
        }
        if ($r->query->getString('bus') === 'none') {
            $where[] = 'd.assigned_bus_id IS NULL';
        }
        if ($r->query->getString('docs') === 'expiring') {
            $where[] = 'd.license_expires_on <= ?';
            $params[] = $this->today()->modify('+' . DocumentStatus::SOON_DAYS . ' days')->format('Y-m-d');
        }
        if (($q = trim($r->query->getString('q'))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(d.first_name LIKE ? OR d.last_name LIKE ? OR CONCAT(d.first_name, ' ', d.last_name) LIKE ?
                        OR d.phone LIKE ? OR d.license_number LIKE ? OR b.code LIKE ? OR b.plate_number LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        $limit  = max(1, min(100, $r->query->getInt('limit', 20)));
        $page   = max(1, $r->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $cond   = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::FROM . " WHERE $cond", $params);
        $rows  = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM
            . " WHERE $cond ORDER BY d.last_name, d.first_name LIMIT $limit OFFSET $offset",
            $params
        );

        return $this->json([
            'total' => $total, 'page' => $page, 'limit' => $limit,
            'items' => array_map(fn (array $d) => $this->present($d), $rows),
        ]);
    }

    #[Route('/stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $lim = $this->today()->modify('+' . DocumentStatus::SOON_DAYS . ' days')->format('Y-m-d');
        $row = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'active'), 0)    AS active,
                    COALESCE(SUM(status = 'on_leave'), 0)  AS on_leave,
                    COALESCE(SUM(status = 'suspended'), 0) AS suspended,
                    COALESCE(SUM(assigned_bus_id IS NULL), 0) AS without_bus,
                    COALESCE(SUM(license_expires_on <= ?), 0) AS docs
             FROM drivers WHERE is_active = 1",
            [$lim]
        );

        return $this->json(array_map('intval', $row));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $d = $this->fetch($id);
        return $d ? $this->json($this->present($d)) : $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

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
        $d = $this->fetch($id);
        if (!$d) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $body = json_decode($r->getContent(), true);
        $new = is_array($body) ? ($body['status'] ?? null) : null;
        if (!in_array($new, self::STATUSES, true)) {
            return $this->invalid(['status' => 'Statut invalide.']);
        }
        $this->db->update('drivers', ['status' => $new], ['id' => $id]);
        $this->crud->audit($user, $r->getClientIp(), 'driver.status', 'driver', $id, ['status' => $d['status']], ['status' => $new]);

        return $this->json(['status' => $new]);
    }

    /** Archiver un chauffeur libère son bus. */
    #[Route('/{id}/flags', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:fleet.manage')]
    public function flags(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $d = $this->fetch($id);
        if (!$d) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $body = json_decode($r->getContent(), true);
        if (!is_array($body) || !array_key_exists('is_active', $body)) {
            return $this->json(['error' => 'nothing_to_update'], Response::HTTP_BAD_REQUEST);
        }
        $active = (int) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN);
        if (!$active && $d['bus_status'] === 'on_mission') {
            return $this->invalid(['is_active' => "Ce chauffeur est en mission : impossible de l'archiver."]);
        }
        $set = ['is_active' => $active] + ($active ? [] : ['assigned_bus_id' => null]);
        $this->db->update('drivers', $set, ['id' => $id]);
        $this->crud->audit($user, $r->getClientIp(), $active ? 'driver.restore' : 'driver.archive', 'driver', $id, null, $set);

        return $this->json(['is_active' => (bool) $active]);
    }

    /** Génère un nouveau PIN à 6 chiffres, renvoyé UNE SEULE FOIS. */
    #[Route('/{id}/reset-pin', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:fleet.manage')]
    public function resetPin(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        if (!$this->fetch($id)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->update('drivers', ['pin_hash' => password_hash($pin, PASSWORD_DEFAULT)], ['id' => $id]);
        $this->crud->audit($user, $r->getClientIp(), 'driver.pin_reset', 'driver', $id, null, ['pin' => '(nouveau code)']);

        $res = $this->json(['pin' => $pin]);
        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:fleet.manage')]
    public function delete(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $d = $this->fetch($id);
        if (!$d) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        try {
            $this->db->delete('drivers', ['id' => $id]);
        } catch (ForeignKeyConstraintViolationException) {
            return $this->json([
                'error'   => 'in_use',
                'message' => "Ce chauffeur a un historique de missions. Archivez-le au lieu de le supprimer.",
            ], Response::HTTP_CONFLICT);
        }
        $this->crud->audit($user, $r->getClientIp(), 'driver.delete', 'driver', $id, $d, null);

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
            fn (array $row, ?array $existing): array => $this->guardBus($row, $existing)
        );

        $pin = $body['pin'] ?? null;
        $pinGiven = is_string($pin) && $pin !== '';
        if ($pinGiven && !preg_match('/^\d{4,8}$/', $pin)) {
            $errors['pin'] = 'Le code PIN doit contenir 4 à 8 chiffres.';
        }
        if ($errors) {
            return $this->invalid($errors);
        }
        if ($pinGiven) {
            $row['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
        }
        if (!$row['is_active']) {
            $row['assigned_bus_id'] = null;           // un chauffeur archivé libère son bus
        }

        $created = $id === null;
        try {
            if ($created) {
                $this->db->insert('drivers', $row);
                $id = (int) $this->db->lastInsertId();
            } else {
                $this->db->update('drivers', $row, ['id' => $id]);
            }
        } catch (UniqueConstraintViolationException $e) {
            return $this->invalid(str_contains($e->getMessage(), 'uq_drivers_assigned_bus')
                ? ['assigned_bus_id' => 'Ce bus vient d\'être attribué à un autre chauffeur.']
                : ['phone' => 'Téléphone ou numéro de permis déjà utilisé.']);
        }

        $after = $row;
        if (isset($after['pin_hash'])) {
            $after['pin_hash'] = '(modifié)';              // jamais de hash dans le journal d'audit
        }
        $this->crud->audit($user, $r->getClientIp(), $created ? 'driver.create' : 'driver.update', 'driver', $id, $before, $after);

        return $this->json($this->present($this->fetch($id)), $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
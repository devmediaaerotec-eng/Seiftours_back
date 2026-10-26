<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\TripPayloadValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/trips')]
#[IsGranted('perm:trips.manage')]
final class TripAdminController extends AbstractController
{
    private const TYPES = ['omra', 'organized', 'circuit'];

    public function __construct(private Connection $db, private TripPayloadValidator $validator) {}

    /** Listes déroulantes du formulaire */
    #[Route('/form-options', methods: ['GET'])]
    public function formOptions(): JsonResponse
    {
        return $this->json([
            'destinations' => $this->db->fetchAllAssociative(
                'SELECT id, name, kind FROM destinations ORDER BY sort_order, name'),
            'regions' => $this->db->fetchAllAssociative(
                'SELECT id, name FROM circuit_regions ORDER BY sort_order, name'),
        ]);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $where = ['1=1'];
        $params = [];

        $raw = $r->query->getString('types') ?: $r->query->getString('type');
        $types = array_values(array_intersect(self::TYPES, array_filter(explode(',', $raw))));
        if ($types) {
            $where[] = 't.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            array_push($params, ...$types);
        }

        $status = $r->query->getString('status');
        if ($status === 'active') {
            $where[] = 't.is_active = 1';
        } elseif ($status === 'hidden') {
            $where[] = 't.is_active = 0';
        }

        if (($q = trim($r->query->getString('q'))) !== '') {
            $where[] = '(t.title LIKE ? OR t.slug LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }

        $limit  = max(1, min(100, $r->query->getInt('limit', 20)));
        $page   = max(1, $r->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $cond   = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM trips t WHERE $cond", $params);
        $items = $this->db->fetchAllAssociative(
            "SELECT t.id, t.type, t.slug, t.title, t.image_url, t.duration_nights,
                    t.price_from_millimes, t.currency, t.is_featured, t.is_active, t.sort_order,
                    d.name AS destination_name, rg.name AS region_name,
                    (SELECT COUNT(*) FROM trip_departures x
                      WHERE x.trip_id = t.id AND x.is_active = 1 AND x.departure_date >= CURDATE()) AS upcoming_departures,
                    (SELECT MIN(x.departure_date) FROM trip_departures x
                      WHERE x.trip_id = t.id AND x.is_active = 1 AND x.departure_date >= CURDATE()) AS next_departure
             FROM trips t
             LEFT JOIN destinations d     ON d.id  = t.destination_id
             LEFT JOIN circuit_regions rg ON rg.id = t.region_id
             WHERE $cond
             ORDER BY t.sort_order, t.id DESC
             LIMIT $limit OFFSET $offset",
            $params
        );

        return $this->json(['total' => $total, 'page' => $page, 'limit' => $limit, 'items' => $items]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $trip = $this->db->fetchAssociative('SELECT * FROM trips WHERE id = ?', [$id]);
        if (!$trip) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $trip['i18n']        = json_decode($trip['i18n'] ?: '{}', true) ?: [];
        $trip['is_active']   = (bool) $trip['is_active'];
        $trip['is_featured'] = (bool) $trip['is_featured'];

        $days = $this->db->fetchAllAssociative(
            'SELECT id, day_number, title, description, i18n FROM trip_days WHERE trip_id = ? ORDER BY day_number', [$id]);
        foreach ($days as &$d) {
            $d['i18n'] = json_decode($d['i18n'] ?: '{}', true) ?: [];
        }
        unset($d);

        return $this->json([
            'trip'       => $trip,
            'days'       => $days,
            'images'     => $this->db->fetchAllAssociative(
                'SELECT id, url, alt, sort_order FROM trip_images WHERE trip_id = ? ORDER BY sort_order, id', [$id]),
            'departures' => $this->db->fetchAllAssociative(
                'SELECT * FROM trip_departures WHERE trip_id = ? ORDER BY departure_date', [$id]),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        return $this->save($r, $user, null);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        return $this->save($r, $user, $id);
    }

    #[Route('/{id}/flags', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function flags(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        if (!$this->db->fetchOne('SELECT 1 FROM trips WHERE id = ?', [$id])) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $body = json_decode($r->getContent(), true);
        $set = [];
        foreach (['is_active', 'is_featured'] as $k) {
            if (is_array($body) && array_key_exists($k, $body)) {
                $set[$k] = (int) filter_var($body[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (!$set) {
            return $this->json(['error' => 'nothing_to_update'], Response::HTTP_BAD_REQUEST);
        }
        $this->db->update('trips', $set, ['id' => $id]);
        $this->audit($r, $user, 'trip.flags', $id, null, $set);

        return $this->json($set);
    }

    #[Route('/{id}/duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $t = $this->db->fetchAssociative('SELECT * FROM trips WHERE id = ?', [$id]);
        if (!$t) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        unset($t['id'], $t['created_at'], $t['updated_at']);

        $base = substr($t['slug'], 0, 120) . '-copie';
        $slug = $base;
        for ($n = 2; $this->db->fetchOne('SELECT 1 FROM trips WHERE slug = ?', [$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        $t['slug'] = $slug;
        $t['title'] = mb_substr($t['title'], 0, 180) . ' (copie)';
        $t['is_active'] = 0;
        $t['is_featured'] = 0;

        $this->db->beginTransaction();
        try {
            $this->db->insert('trips', $t);
            $newId = (int) $this->db->lastInsertId();
            $this->db->executeStatement(
                'INSERT INTO trip_days (trip_id, day_number, title, description, i18n)
                 SELECT ?, day_number, title, description, i18n FROM trip_days WHERE trip_id = ?', [$newId, $id]);
            $this->db->executeStatement(
                'INSERT INTO trip_images (trip_id, url, alt, sort_order)
                 SELECT ?, url, alt, sort_order FROM trip_images WHERE trip_id = ?', [$newId, $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->audit($r, $user, 'trip.duplicate', $newId, ['source_id' => $id], ['slug' => $slug]);

        return $this->json(['id' => $newId, 'slug' => $slug], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $trip = $this->db->fetchAssociative('SELECT id, slug, title FROM trips WHERE id = ?', [$id]);
        if (!$trip) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $used = (int) $this->db->fetchOne(
            'SELECT (SELECT COUNT(*) FROM trip_requests WHERE trip_id = ?) + (SELECT COUNT(*) FROM deals WHERE trip_id = ?)',
            [$id, $id]
        );
        if ($used > 0) {
            return $this->json([
                'error'   => 'in_use',
                'message' => "Ce voyage est lié à $used demande(s) ou bon(s) plan(s). Masquez-le au lieu de le supprimer.",
            ], Response::HTTP_CONFLICT);
        }
        $this->db->delete('trips', ['id' => $id]);      // jours, images et départs partent en cascade
        $this->audit($r, $user, 'trip.delete', $id, $trip, null);

        return $this->json(['ok' => true]);
    }

    // ------------------------------------------------------------------

    private function save(Request $r, StaffUser $user, ?int $id): JsonResponse
    {
        $before = null;
        if ($id !== null) {
            $before = $this->db->fetchAssociative('SELECT * FROM trips WHERE id = ?', [$id]);
            if (!$before) {
                return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
            }
        }

        $payload = json_decode($r->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        [$data, $errors] = $this->validator->validate($payload, $id);
        if ($errors) {
            return $this->invalid($errors);
        }

        try {
            $tripId = $this->persist($id, $data);
        } catch (UniqueConstraintViolationException $e) {
            return $this->invalid(str_contains($e->getMessage(), 'uq_trips_slug')
                ? ['slug' => 'Ce slug est déjà utilisé.']
                : ['departures' => 'Deux départs ont la même date.']);
        }

        $this->audit($r, $user, $id === null ? 'trip.create' : 'trip.update', $tripId, $before, $data['trip']);

        return $this->json(['id' => $tripId, 'slug' => $data['trip']['slug']],
            $id === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    private function persist(?int $id, array $d): int
    {
        $this->db->beginTransaction();
        try {
            if ($id === null) {
                $this->db->insert('trips', $d['trip']);
                $id = (int) $this->db->lastInsertId();
            } else {
                $this->db->update('trips', $d['trip'], ['id' => $id]);
            }

            // Jours et images : rien ne les référence, on remplace tout
            $this->db->delete('trip_days', ['trip_id' => $id]);
            foreach ($d['days'] as $day) {
                $this->db->insert('trip_days', ['trip_id' => $id] + $day);
            }
            $this->db->delete('trip_images', ['trip_id' => $id]);
            foreach ($d['images'] as $img) {
                $this->db->insert('trip_images', ['trip_id' => $id] + $img);
            }

            // Départs : synchronisés par identifiant (les demandes de devis pointent dessus)
            $existing = array_map('intval', $this->db->fetchFirstColumn(
                'SELECT id FROM trip_departures WHERE trip_id = ?', [$id]));
            $keep = array_values(array_intersect($existing, array_filter(array_column($d['departures'], 'id'))));
            foreach (array_diff($existing, $keep) as $removeId) {
                $this->db->delete('trip_departures', ['id' => $removeId]);
            }
            foreach ($d['departures'] as $dep) {
                $depId = $dep['id'];
                unset($dep['id']);
                if ($depId && in_array((int) $depId, $keep, true)) {
                    $this->db->update('trip_departures', $dep, ['id' => $depId, 'trip_id' => $id]);
                } else {
                    $this->db->insert('trip_departures', ['trip_id' => $id] + $dep);
                }
            }

            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function invalid(array $violations): JsonResponse
    {
        return $this->json([
            'error'      => 'validation_failed',
            'message'    => 'Certains champs sont invalides.',
            'violations' => $violations,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function audit(Request $r, StaffUser $u, string $action, int $id, ?array $before, ?array $after): void
    {
        $enc = fn (?array $a) => $a === null ? null
            : json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->db->insert('audit_log', [
            'staff_id'    => $u->getId(),
            'action'      => $action,
            'entity_type' => 'trip',
            'entity_id'   => (string) $id,
            'before_data' => $enc($before),
            'after_data'  => $enc($after),
            'ip'          => $r->getClientIp(),
        ]);
    }
}
<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\CatalogCrud;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/branches')]
#[IsGranted('perm:settings.manage')]
final class BranchAdminController extends AbstractController
{
    private const SELECT = 'b.id, b.code, b.name, b.city, b.address, b.phone, b.email, b.maps_url, b.opening_hours, b.is_active, b.created_at,
        (SELECT COUNT(*) FROM staff_users s WHERE s.branch_id = b.id) AS staff_count,
        (SELECT COUNT(*) FROM bookings k WHERE k.branch_id = b.id) AS bookings_count';
    private const DAYS = [1, 2, 3, 4, 5, 6, 0];          // lundi → dimanche (0 = dimanche, comme le front)

    public function __construct(private Connection $db, private CatalogCrud $crud) {}

    private function fetch(int $id): ?array
    {
        $r = $this->db->fetchAssociative('SELECT ' . self::SELECT . ' FROM branches b WHERE b.id = ?', [$id]);
        return $r ? $this->present($r) : null;
    }

    private function present(array $b): array
    {
        $stored = json_decode((string) $b['opening_hours'], true) ?: [];
        $hours = [];
        foreach (self::DAYS as $d) {
            $v = $stored[$d] ?? $stored[(string) $d] ?? null;
            $hours[] = ['day' => $d, 'open' => is_array($v), 'from' => $v[0] ?? null, 'to' => $v[1] ?? null];
        }
        $b['opening_hours'] = $hours;
        $b['is_active'] = (bool) $b['is_active'];
        $b['staff_count'] = (int) $b['staff_count'];
        $b['bookings_count'] = (int) $b['bookings_count'];
        return $b;
    }

    private function invalid(array $v): JsonResponse
    {
        return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.', 'violations' => $v], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $rows = $this->db->fetchAllAssociative('SELECT ' . self::SELECT . ' FROM branches b ORDER BY b.is_active DESC, b.name');
        return $this->json(['items' => array_map(fn (array $b) => $this->present($b), $rows)]);
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
        if (!$this->fetch($id)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $b = json_decode($r->getContent(), true);
        if (!is_array($b) || !array_key_exists('is_active', $b)) {
            return $this->json(['error' => 'nothing_to_update'], Response::HTTP_BAD_REQUEST);
        }
        $active = (int) filter_var($b['is_active'], FILTER_VALIDATE_BOOLEAN);
        $this->db->update('branches', ['is_active' => $active], ['id' => $id]);
        $this->crud->audit($user, $r->getClientIp(), 'branch.flags', 'branch', $id, null, ['is_active' => $active]);

        return $this->json(['is_active' => (bool) $active]);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $b = $this->fetch($id);
        if (!$b) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $used = (int) $this->db->fetchOne(
            'SELECT (SELECT COUNT(*) FROM staff_users WHERE branch_id = ?) + (SELECT COUNT(*) FROM bookings WHERE branch_id = ?)
                  + (SELECT COUNT(*) FROM payments WHERE branch_id = ?) + (SELECT COUNT(*) FROM trip_requests WHERE branch_id = ?)',
            [$id, $id, $id, $id]
        );
        if ($used > 0) {
            return $this->json(['error' => 'in_use', 'message' => "Cette agence est utilisée par $used élément(s). Désactivez-la au lieu de la supprimer."], Response::HTTP_CONFLICT);
        }
        $this->db->delete('branches', ['id' => $id]);
        $this->crud->audit($user, $r->getClientIp(), 'branch.delete', 'branch', $id, ['code' => $b['code'], 'name' => $b['name']], null);

        return $this->json(['ok' => true]);
    }

    private function save(Request $r, StaffUser $user, ?int $id): JsonResponse
    {
        $before = $id ? $this->fetch($id) : null;
        if ($id && !$before) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $b = json_decode($r->getContent(), true);
        if (!is_array($b)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        $row = [];
        foreach (['name' => [120, true], 'city' => [80, true], 'address' => [255, true]] as $k => [$max, $required]) {
            $v = trim((string) ($b[$k] ?? ''));
            if ($v === '' && $required) {
                $errors[$k] = 'Champ requis.';
            } elseif (mb_strlen($v) > $max) {
                $errors[$k] = "$max caractères maximum.";
            }
            $row[$k] = $v;
        }
        $phone = trim((string) ($b['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^[+0-9 ().\-]{6,30}$/', $phone)) {
            $errors['phone'] = 'Numéro invalide.';
        }
        $row['phone'] = $phone !== '' ? $phone : null;
        $email = trim((string) ($b['email'] ?? ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            $errors['email'] = 'E-mail invalide.';
        }
        $row['email'] = $email !== '' ? $email : null;
        $maps = trim((string) ($b['maps_url'] ?? ''));
        if ($maps !== '' && (!preg_match('#^https?://#i', $maps) || mb_strlen($maps) > 500)) {
            $errors['maps_url'] = 'Lien invalide (doit commencer par http:// ou https://).';
        }
        $row['maps_url'] = $maps !== '' ? $maps : null;
        $row['is_active'] = (int) filter_var($b['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($id === null) {                                   // le code ne change plus une fois créé
            $code = trim((string) ($b['code'] ?? ''));
            if (!preg_match('/^[a-z0-9_-]{2,40}$/', $code)) {
                $errors['code'] = 'Minuscules, chiffres, tirets (2 à 40 caractères).';
            } elseif ($this->db->fetchOne('SELECT 1 FROM branches WHERE code = ?', [$code])) {
                $errors['code'] = 'Ce code existe déjà.';
            }
            $row['code'] = $code;
        }

        // Horaires : liste de 7 jours { day, open, from, to }
        $obj = new \stdClass();
        $seen = [];
        foreach (is_array($b['opening_hours'] ?? null) ? $b['opening_hours'] : [] as $i => $h) {
            $day = is_array($h) ? ($h['day'] ?? null) : null;
            if (!is_int($day) || $day < 0 || $day > 6 || isset($seen[$day])) {
                $errors["opening_hours.$i"] = 'Jour invalide.';
                continue;
            }
            $seen[$day] = true;
            if (empty($h['open'])) {
                $obj->{(string) $day} = null;
                continue;
            }
            $from = (string) ($h['from'] ?? '');
            $to = (string) ($h['to'] ?? '');
            $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
            if (!preg_match($re, $from) || !preg_match($re, $to) || $from >= $to) {
                $errors["opening_hours.$i"] = "Horaires invalides (l'ouverture doit précéder la fermeture).";
                continue;
            }
            $obj->{(string) $day} = [$from, $to];
        }
        foreach (self::DAYS as $d) {
            if (!isset($seen[$d])) {
                $obj->{(string) $d} = null;
            }
        }
        $row['opening_hours'] = json_encode($obj, JSON_THROW_ON_ERROR);

        if ($errors) {
            return $this->invalid($errors);
        }

        $created = $id === null;
        if ($created) {
            $this->db->insert('branches', $row);
            $id = (int) $this->db->lastInsertId();
        } else {
            $this->db->update('branches', $row, ['id' => $id]);
        }
        $this->crud->audit($user, $r->getClientIp(), $created ? 'branch.create' : 'branch.update', 'branch', $id, $before, $row);

        return $this->json($this->fetch($id), $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
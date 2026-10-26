<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\CatalogCrud;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/staff')]
#[IsGranted('perm:staff.manage')]
final class StaffAdminController extends AbstractController
{
    private const SELECT = 's.id, s.email, s.full_name, s.phone, s.role_id, r.code AS role_code, r.label AS role_label,
        s.branch_id, b.name AS branch_name, s.is_active, s.last_login_at, s.created_at';
    private const FROM = 'staff_users s JOIN roles r ON r.id = s.role_id LEFT JOIN branches b ON b.id = s.branch_id';

    public function __construct(private Connection $db, private CatalogCrud $crud, private PasswordHasherFactoryInterface $hashers) {}

    private function fetch(int $id): ?array
    {
        $r = $this->db->fetchAssociative('SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE s.id = ?', [$id]);
        return $r ? $this->present($r) : null;
    }

    private function present(array $u): array
    {
        $u['id'] = (int) $u['id'];
        $u['role_id'] = (int) $u['role_id'];
        $u['branch_id'] = $u['branch_id'] === null ? null : (int) $u['branch_id'];
        $u['is_active'] = (bool) $u['is_active'];
        return $u;
    }

    private function invalid(array $v, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return $this->json(['error' => 'validation_failed', 'message' => 'Opération refusée.', 'violations' => $v], $status);
    }

    private function tempPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $p = '';
        for ($i = 0; $i < 12; $i++) {
            $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $p;
    }

    private function hash(string $plain): string
    {
        return $this->hashers->getPasswordHasher(StaffUser::class)->hash($plain);
    }

    private function otherActiveSuperAdmins(int $exceptId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM staff_users s JOIN roles r ON r.id = s.role_id
             WHERE r.code = 'super_admin' AND s.is_active = 1 AND s.id <> ?", [$exceptId]);
    }

    private function noSecret(?array $u): ?array
    {
        return $u === null ? null : array_diff_key($u, ['password_hash' => 1]);
    }

    #[Route('/form-options', methods: ['GET'])]
    public function formOptions(): JsonResponse
    {
        return $this->json([
            'roles'    => $this->db->fetchAllAssociative('SELECT id, code, label FROM roles ORDER BY label'),
            'branches' => $this->db->fetchAllAssociative('SELECT id, code, name FROM branches WHERE is_active = 1 ORDER BY name'),
        ]);
    }

    #[Route('/stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $row = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS inactive,
                    COALESCE(SUM(is_active = 1 AND last_login_at IS NULL), 0) AS never
             FROM staff_users');
        return $this->json(array_map('intval', $row));
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $where = ['1=1'];
        $params = [];
        if (($role = $r->query->getInt('role')) > 0) {
            $where[] = 's.role_id = ?';
            $params[] = $role;
        }
        $status = $r->query->getString('status');
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 's.is_active = ?';
            $params[] = $status === 'active' ? 1 : 0;
        }
        if (($q = trim($r->query->getString('q'))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(s.full_name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $limit  = max(1, min(100, $r->query->getInt('limit', 20)));
        $page   = max(1, $r->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $cond   = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::FROM . " WHERE $cond", $params);
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . " WHERE $cond ORDER BY s.is_active DESC, s.full_name LIMIT $limit OFFSET $offset", $params);

        return $this->json(['total' => $total, 'page' => $page, 'limit' => $limit, 'items' => array_map(fn (array $u) => $this->present($u), $rows)]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $r, #[CurrentUser] StaffUser $me): JsonResponse
    {
        return $this->save($r, $me, null);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $r, #[CurrentUser] StaffUser $me): JsonResponse
    {
        return $this->save($r, $me, $id);
    }

    #[Route('/{id}/flags', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function flags(int $id, Request $r, #[CurrentUser] StaffUser $me): JsonResponse
    {
        $u = $this->fetch($id);
        if (!$u) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $b = json_decode($r->getContent(), true);
        if (!is_array($b) || !array_key_exists('is_active', $b)) {
            return $this->json(['error' => 'nothing_to_update'], Response::HTTP_BAD_REQUEST);
        }
        $active = (bool) filter_var($b['is_active'], FILTER_VALIDATE_BOOLEAN);

        $errors = [];
        if (!$active && $id === $me->getId()) {
            $errors['is_active'] = 'Vous ne pouvez pas désactiver votre propre compte.';
        }
        if ($u['role_code'] === 'super_admin' && $me->getRole()->getCode() !== 'super_admin') {
            $errors['is_active'] = 'Seul un super administrateur peut gérer ce compte.';
        }
        if (!$active && $u['role_code'] === 'super_admin' && $u['is_active'] && $this->otherActiveSuperAdmins($id) === 0) {
            $errors['is_active'] = 'Il doit rester au moins un super administrateur actif.';
        }
        if ($errors) {
            return $this->invalid($errors, Response::HTTP_CONFLICT);
        }

        $this->db->update('staff_users', ['is_active' => (int) $active], ['id' => $id]);
        $this->crud->audit($me, $r->getClientIp(), $active ? 'staff.activate' : 'staff.deactivate', 'staff', $id, null, ['is_active' => $active]);

        return $this->json(['is_active' => $active]);
    }

    /** Génère un mot de passe provisoire, renvoyé UNE SEULE FOIS. */
    #[Route('/{id}/reset-password', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetPassword(int $id, Request $r, #[CurrentUser] StaffUser $me): JsonResponse
    {
        $u = $this->fetch($id);
        if (!$u) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        if ($id === $me->getId()) {
            return $this->invalid(['password' => 'Utilisez « Mon compte » pour changer votre propre mot de passe.'], Response::HTTP_CONFLICT);
        }
        if ($u['role_code'] === 'super_admin' && $me->getRole()->getCode() !== 'super_admin') {
            return $this->invalid(['password' => 'Seul un super administrateur peut gérer ce compte.'], Response::HTTP_CONFLICT);
        }
        $plain = $this->tempPassword();
        $this->db->update('staff_users', ['password_hash' => $this->hash($plain)], ['id' => $id]);
        $this->crud->audit($me, $r->getClientIp(), 'staff.password_reset', 'staff', $id, null, ['password' => '(nouveau mot de passe)']);

        $res = $this->json(['temp_password' => $plain]);
        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }

    private function save(Request $r, StaffUser $me, ?int $id): JsonResponse
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
        $name = trim((string) ($b['full_name'] ?? ''));
        if ($name === '') {
            $errors['full_name'] = 'Champ requis.';
        } elseif (mb_strlen($name) > 160) {
            $errors['full_name'] = '160 caractères maximum.';
        }
        $row['full_name'] = $name;

        $email = mb_strtolower(trim((string) ($b['email'] ?? '')));
        if ($email === '') {
            $errors['email'] = 'Champ requis.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = 'E-mail invalide.';
        } elseif ($this->db->fetchOne('SELECT 1 FROM staff_users WHERE email = ? AND id <> ?', [$email, $id ?? 0])) {
            $errors['email'] = 'Cette adresse e-mail est déjà utilisée.';
        }
        $row['email'] = $email;

        $phone = trim((string) ($b['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^[+0-9 ().\-]{6,30}$/', $phone)) {
            $errors['phone'] = 'Numéro invalide.';
        }
        $row['phone'] = $phone !== '' ? $phone : null;

        $roleId = $b['role_id'] ?? null;
        $roleCode = is_int($roleId) ? $this->db->fetchOne('SELECT code FROM roles WHERE id = ?', [$roleId]) : false;
        if ($roleCode === false) {
            $errors['role_id'] = 'Choisissez un rôle.';
        }
        $row['role_id'] = $roleId;

        $branchId = $b['branch_id'] ?? null;
        if ($branchId !== null && (!is_int($branchId) || !$this->db->fetchOne('SELECT 1 FROM branches WHERE id = ?', [$branchId]))) {
            $errors['branch_id'] = 'Agence introuvable.';
        }
        $row['branch_id'] = $branchId;

        if (!isset($errors['role_id'])) {
            $iAmSuper = $me->getRole()->getCode() === 'super_admin';
            if (($roleCode === 'super_admin' || ($before['role_code'] ?? null) === 'super_admin') && !$iAmSuper) {
                $errors['role_id'] = 'Seul un super administrateur peut gérer ce rôle.';
            } elseif ($before && $id === $me->getId() && $before['role_id'] !== $roleId) {
                $errors['role_id'] = 'Vous ne pouvez pas modifier votre propre rôle.';
            } elseif ($before && $before['role_code'] === 'super_admin' && $before['is_active'] && $roleCode !== 'super_admin'
                      && $this->otherActiveSuperAdmins($id) === 0) {
                $errors['role_id'] = 'Il doit rester au moins un super administrateur actif.';
            }
        }
        if ($errors) {
            return $this->invalid($errors);
        }

        $created = $id === null;
        $temp = null;
        if ($created) {
            $temp = $this->tempPassword();
            $row['password_hash'] = $this->hash($temp);
            $this->db->insert('staff_users', $row);
            $id = (int) $this->db->lastInsertId();
        } else {
            $this->db->update('staff_users', $row, ['id' => $id]);
        }
        $this->crud->audit($me, $r->getClientIp(), $created ? 'staff.create' : 'staff.update', 'staff', $id, $before, $this->noSecret($row));

        $res = $this->json(['user' => $this->fetch($id), 'temp_password' => $temp], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }
}
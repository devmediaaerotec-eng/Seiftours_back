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

#[Route('/api/admin/roles')]
#[IsGranted('perm:staff.manage')]
final class RoleAdminController extends AbstractController
{
    public function __construct(private Connection $db, private CatalogCrud $crud) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $roles = $this->db->fetchAllAssociative(
            "SELECT r.id, r.code, r.label, (SELECT COUNT(*) FROM staff_users s WHERE s.role_id = r.id AND s.is_active = 1) AS staff_count
             FROM roles r ORDER BY r.code = 'super_admin' DESC, r.label");
        $perms = $this->db->fetchAllAssociative('SELECT role_id, permission_code FROM role_permissions');
        $byRole = [];
        foreach ($perms as $p) {
            $byRole[(int) $p['role_id']][] = $p['permission_code'];
        }
        foreach ($roles as &$r) {
            $r['id'] = (int) $r['id'];
            $r['staff_count'] = (int) $r['staff_count'];
            $r['permissions'] = $byRole[$r['id']] ?? [];
        }
        unset($r);

        return $this->json([
            'roles' => $roles,
            'permissions' => $this->db->fetchAllAssociative('SELECT code, description FROM permissions ORDER BY code'),
        ]);
    }

    #[Route('/{id}/permissions', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function save(int $id, Request $r, #[CurrentUser] StaffUser $me): JsonResponse
    {
        $role = $this->db->fetchAssociative('SELECT id, code FROM roles WHERE id = ?', [$id]);
        if (!$role) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        if ($role['code'] === 'super_admin') {
            return $this->json(['error' => 'locked', 'message' => 'Le rôle super administrateur a toujours tous les droits.'], Response::HTTP_CONFLICT);
        }
        $b = json_decode($r->getContent(), true);
        $codes = is_array($b) && is_array($b['permissions'] ?? null) ? array_values(array_unique(array_map('strval', $b['permissions']))) : null;
        if ($codes === null) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Liste de droits invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $valid = $this->db->fetchFirstColumn('SELECT code FROM permissions');
        if (array_diff($codes, $valid)) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Droit inconnu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $before = $this->db->fetchFirstColumn('SELECT permission_code FROM role_permissions WHERE role_id = ?', [$id]);
        $this->db->beginTransaction();
        try {
            $this->db->delete('role_permissions', ['role_id' => $id]);
            foreach ($codes as $c) {
                $this->db->insert('role_permissions', ['role_id' => $id, 'permission_code' => $c]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->crud->audit($me, $r->getClientIp(), 'role.permissions', 'role', $id, ['permissions' => $before], ['permissions' => $codes]);

        return $this->json(['ok' => true]);
    }
}
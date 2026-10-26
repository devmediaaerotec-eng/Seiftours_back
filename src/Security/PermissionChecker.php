<?php
namespace App\Security;

use App\Entity\StaffUser;
use Doctrine\DBAL\Connection;

final class PermissionChecker
{
    /** @var array<int, string[]> */
    private array $cache = [];

    public function __construct(private Connection $db) {}

    public function has(StaffUser $user, string $permission): bool
    {
        $roleId = $user->getRole()->getId();
        $this->cache[$roleId] ??= $this->db->fetchFirstColumn(
            'SELECT permission_code FROM role_permissions WHERE role_id = ?',
            [$roleId]
        );
        return in_array($permission, $this->cache[$roleId], true);
    }
}
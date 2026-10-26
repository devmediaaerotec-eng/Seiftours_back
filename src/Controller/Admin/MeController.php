<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController extends AbstractController
{
    #[Route('/api/admin/me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] StaffUser $user, Connection $db): JsonResponse
    {
        return $this->json([
            'id'          => $user->getId(),
            'email'       => $user->getEmail(),
            'fullName'    => $user->getFullName(),
            'role'        => $user->getRole()->getCode(),
            'branch'      => $user->getBranch()?->getCode(),
            'permissions' => $db->fetchFirstColumn(
                'SELECT permission_code FROM role_permissions WHERE role_id = ?',
                [$user->getRole()->getId()]
            ),
        ]);
    }
}
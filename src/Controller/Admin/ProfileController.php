<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ProfileController extends AbstractController
{
    #[Route('/api/admin/me/password', methods: ['PUT'])]
    public function __invoke(Request $r, #[CurrentUser] StaffUser $user, UserPasswordHasherInterface $hasher, Connection $db): JsonResponse
    {
        $b = json_decode($r->getContent(), true);
        $cur = is_array($b) ? (string) ($b['current_password'] ?? '') : '';
        $new = is_array($b) ? (string) ($b['new_password'] ?? '') : '';

        $errors = [];
        if (!$hasher->isPasswordValid($user, $cur)) {
            $errors['current_password'] = 'Mot de passe actuel incorrect.';
        }
        if (mb_strlen($new) < 10) {
            $errors['new_password'] = '10 caractères minimum.';
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $errors['new_password'] = 'Au moins une lettre et un chiffre.';
        } elseif ($new === $cur) {
            $errors['new_password'] = "Le nouveau mot de passe doit être différent de l'actuel.";
        }
        if ($errors) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.', 'violations' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $db->update('staff_users', ['password_hash' => $hasher->hashPassword($user, $new)], ['id' => $user->getId()]);
        return $this->json(['ok' => true]);
    }
}
<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\CatalogCrud;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/settings')]
#[IsGranted('perm:settings.manage')]
final class SettingsAdminController extends AbstractController
{
    public function __construct(private SettingsService $settings, private CatalogCrud $crud) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['groups' => $this->settings->describe()]);
    }

    #[Route('', methods: ['PUT'])]
    public function save(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $b = json_decode($r->getContent(), true);
        $values = is_array($b) && is_array($b['values'] ?? null) ? $b['values'] : null;
        if (!$values) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Aucune valeur reçue.'], Response::HTTP_BAD_REQUEST);
        }
        [$before, $errors, $after] = $this->settings->save($values, $user->getId());
        if ($errors) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Certaines valeurs sont invalides.', 'violations' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->crud->audit($user, $r->getClientIp(), 'settings.update', 'settings', 0, $before, $after);

        return $this->json(['groups' => $this->settings->describe()]);
    }
}
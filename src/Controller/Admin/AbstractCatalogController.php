<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\CatalogCrud;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

abstract class AbstractCatalogController extends AbstractController
{
    public function __construct(protected CatalogCrud $crud) {}

    abstract protected function config(): array;

    /** Règles métier supplémentaires : fn(array $row, ?array $existing): array<string,string> */
    protected function guard(): ?callable
    {
        return null;
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        return $this->json(['items' => $this->crud->all($this->config())]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $row = $this->crud->find($this->config(), $id);
        return $row ? $this->json($row) : $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
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
        $cfg = $this->config();
        if (!$this->crud->find($cfg, $id)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $body = json_decode($r->getContent(), true);
        $set = $this->crud->flags($cfg, $id, is_array($body) ? $body : []);
        if (!$set) {
            return $this->json(['error' => 'nothing_to_update'], Response::HTTP_BAD_REQUEST);
        }
        $this->crud->audit($user, $r->getClientIp(), "{$cfg['entity']}.flags", $cfg['entity'], $id, null, $set);

        return $this->json($set);
    }

    #[Route('/reorder', methods: ['POST'])]
    public function reorder(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $cfg = $this->config();
        $body = json_decode($r->getContent(), true);
        $ids = is_array($body) ? ($body['ids'] ?? null) : null;
        if (!is_array($ids) || !$this->crud->reorder($cfg, $ids)) {
            return $this->json(['error' => 'invalid_order', 'message' => "Liste d'identifiants invalide."], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->crud->audit($user, $r->getClientIp(), "{$cfg['entity']}.reorder", $cfg['entity'], 0, null, ['ids' => $ids]);

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $cfg = $this->config();
        $row = $this->crud->find($cfg, $id);
        if (!$row) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $used = $this->crud->usage($cfg, $id);
        if ($used > 0) {
            return $this->json([
                'error'   => 'in_use',
                'message' => "Utilisé par $used élément(s). Masquez-le au lieu de le supprimer.",
            ], Response::HTTP_CONFLICT);
        }
        try {
            $this->crud->remove($cfg, $id);
        } catch (ForeignKeyConstraintViolationException) {
            return $this->json(['error' => 'in_use', 'message' => 'Élément encore utilisé ailleurs. Masquez-le.'], Response::HTTP_CONFLICT);
        }
        $this->crud->audit($user, $r->getClientIp(), "{$cfg['entity']}.delete", $cfg['entity'], $id, $row, null);

        return $this->json(['ok' => true]);
    }

    protected function save(Request $r, StaffUser $user, ?int $id): JsonResponse
    {
        $cfg = $this->config();
        $before = null;
        if ($id !== null) {
            $before = $this->crud->find($cfg, $id);
            if (!$before) {
                return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
            }
        }

        $body = json_decode($r->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        [$row, $errors] = $this->crud->validate($cfg, $body, $id, $this->guard());
        if ($errors) {
            return $this->json([
                'error'      => 'validation_failed',
                'message'    => 'Certains champs sont invalides.',
                'violations' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $created = $id === null;
        if ($created) {
            $id = $this->crud->insert($cfg, $row);
        } else {
            $this->crud->update($cfg, $id, $row);
        }
        $this->crud->audit($user, $r->getClientIp(), $cfg['entity'] . ($created ? '.create' : '.update'), $cfg['entity'], $id, $before, $row);

        return $this->json($this->crud->find($cfg, $id), $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
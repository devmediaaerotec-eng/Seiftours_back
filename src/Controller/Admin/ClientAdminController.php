<?php
namespace App\Controller\Admin;
use App\Entity\StaffUser;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/fleet/clients')]
#[IsGranted('perm:fleet.read')]
final class ClientAdminController extends AbstractController
{
 
    public function __construct(private Connection $db) {}

    private function invalid(array $v): JsonResponse
    {
        return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.', 'violations' => $v], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array{0: array, 1: array<string,string>} */
    private function clean(Request $r, int $exclude = 0): array
    {
        $b = json_decode($r->getContent(), true);
        $b = is_array($b) ? $b : [];
        $str = static fn (string $k, int $max) => ($v = trim((string) ($b[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $row = ['name' => $str('name', 160), 'contact_name' => $str('contact_name', 160),'phone' => $str('phone', 30), 'email' => $str('email', 190),
                'address' => $str('address', 255), 'notes' => $str('notes', 2000)];
        $e = [];
        if ($row['name'] === null || mb_strlen($row['name']) < 2) {
            $e['name'] = 'Nom requis (2 caractères minimum).';
        } elseif ($this->db->fetchOne('SELECT 1 FROM clients WHERE name = ? AND id <> ?', [$row['name'], $exclude])) {
            $e['name'] = 'Ce client existe déjà.';
        }
        if ($row['phone'] !== null && !preg_match('/^[+0-9 ().\-]{6,30}$/', $row['phone'])) {
            $e['phone'] = 'Numéro invalide.';
        }
        if ($row['email'] !== null && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'Email invalide.';
        }
        return [$row, $e];
    }


   private const COLS = 'c.id, c.name, c.contact_name, c.phone, c.email, c.address, c.notes, c.is_active';

#[Route('', methods: ['GET'])]
public function list(Request $r): JsonResponse
{
    $where = $r->query->getInt('all') ? '1=1' : 'c.is_active = 1';
    $rows = $this->db->fetchAllAssociative(
        'SELECT ' . self::COLS . ",
            (SELECT COUNT(*) FROM bus_missions bm WHERE bm.client_id = c.id) AS missions_count,
            CASE
              WHEN EXISTS (SELECT 1 FROM client_invitations i WHERE i.client_id = c.id AND i.accepted_at IS NOT NULL) THEN 'accepted'
              WHEN EXISTS (SELECT 1 FROM client_invitations i WHERE i.client_id = c.id AND i.accepted_at IS NULL
                           AND i.revoked_at IS NULL AND i.expires_at > UTC_TIMESTAMP()) THEN 'pending'
              ELSE 'none'
            END AS invite_state
         FROM clients c WHERE $where ORDER BY c.name"
    );
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['missions_count'] = (int) $row['missions_count'];
    }
    unset($row);

    return $this->json(['items' => $rows]);
}

    #[Route('', methods: ['POST'])]
    #[IsGranted('perm:missions.manage')]
    public function create(Request $r): JsonResponse
    {
        [$row, $e] = $this->clean($r);
        if ($e) {
            return $this->invalid($e);
        }
        $this->db->insert('clients', $row);
        $client = $this->db->fetchAssociative('SELECT ' . self::COLS . ' FROM clients c WHERE c.id = ?', [$this->db->lastInsertId()]);

        return $this->json(['client' => $client], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:missions.manage')]
    public function update(int $id, Request $r): JsonResponse
    {
        if (!$this->db->fetchOne('SELECT 1 FROM clients WHERE id = ?', [$id])) {
            return $this->json(['error' => 'not_found', 'message' => 'Client introuvable.'], Response::HTTP_NOT_FOUND);
        }
        [$row, $e] = $this->clean($r, $id);
        if ($e) {
            return $this->invalid($e);
        }
        $this->db->update('clients', $row, ['id' => $id]);

        return $this->json(['client' => $this->db->fetchAssociative('SELECT ' . self::COLS . ' FROM clients c WHERE c.id = ?', [$id])]);
    }


#[Route('/{id}/restore', methods: ['POST'], requirements: ['id' => '\d+'])]
#[IsGranted('perm:missions.manage')]
public function restore(int $id): JsonResponse
{
    $this->db->update('clients', ['is_active' => 1], ['id' => $id]);
    return $this->json(['ok' => true]);
}

/** Génère un lien d'invitation (valable 7 jours). Le jeton n'est stocké que sous forme de hash. */
#[Route('/{id}/invite', methods: ['POST'], requirements: ['id' => '\d+'])]
#[IsGranted('perm:missions.manage')]
public function invite(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
{
    $client = $this->db->fetchAssociative('SELECT id, email FROM clients WHERE id = ? AND is_active = 1', [$id]);
    if (!$client) {
        return $this->json(['error' => 'not_found', 'message' => 'Client introuvable.'], Response::HTTP_NOT_FOUND);
    }
    $b = json_decode($r->getContent(), true);
    $email = trim((string) (is_array($b) ? ($b['email'] ?? $client['email']) : $client['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->invalid(['email' => 'Email valide requis pour inviter ce client.']);
    }

    // une seule invitation en attente à la fois
    $this->db->executeStatement(
        'UPDATE client_invitations SET revoked_at = UTC_TIMESTAMP() WHERE client_id = ? AND accepted_at IS NULL AND revoked_at IS NULL',
        [$id]
    );
    $token = bin2hex(random_bytes(32));
    $this->db->executeStatement(
        'INSERT INTO client_invitations (client_id, email, token_hash, expires_at, created_by)
         VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY), ?)',
        [$id, $email, hash('sha256', $token), $user->getId()]
    );
    if (!$client['email']) {
        $this->db->update('clients', ['email' => $email], ['id' => $id]);
    }

    return $this->json(['token' => $token, 'email' => $email, 'expires_in_days' => 7], Response::HTTP_CREATED);
}

#[Route('/{id}/invite', methods: ['DELETE'], requirements: ['id' => '\d+'])]
#[IsGranted('perm:missions.manage')]
public function revokeInvite(int $id): JsonResponse
{
    $n = $this->db->executeStatement(
        'UPDATE client_invitations SET revoked_at = UTC_TIMESTAMP() WHERE client_id = ? AND accepted_at IS NULL AND revoked_at IS NULL',
        [$id]
    );
    return $this->json(['revoked' => $n]);
}

    /** Un client utilisé par des missions est archivé, sinon supprimé. */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('perm:missions.manage')]
    public function delete(int $id): JsonResponse
    {
        if ($this->db->fetchOne('SELECT 1 FROM bus_missions WHERE client_id = ? LIMIT 1', [$id])) {
            $this->db->update('clients', ['is_active' => 0], ['id' => $id]);
            return $this->json(['archived' => true]);
        }
        $this->db->delete('clients', ['id' => $id]);

        return $this->json(['deleted' => true]);
    }
}
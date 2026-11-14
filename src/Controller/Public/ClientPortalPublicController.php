<?php
namespace App\Controller\Public;

use App\Entity\ClientUser;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/public')]
final class ClientPortalPublicController extends AbstractController
{
    public function __construct(
        private Connection $db,
        private UserPasswordHasherInterface $hasher,
        private JWTTokenManagerInterface $jwt,
    ) {}

    private function invitation(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return $this->db->fetchAssociative(
            'SELECT i.id, i.client_id, i.email, c.name AS client_name
             FROM client_invitations i JOIN clients c ON c.id = i.client_id
             WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL
               AND i.expires_at > UTC_TIMESTAMP() AND c.is_active = 1',
            [hash('sha256', $token)]
        ) ?: null;
    }

    private function gone(): JsonResponse
    {
        return $this->json(['error' => 'invalid_invitation', 'message' => 'Ce lien est invalide ou expiré. Demandez une nouvelle invitation.'], Response::HTTP_GONE);
    }

    #[Route('/client-invite/{token}', methods: ['GET'])]
    public function check(string $token): JsonResponse
    {
        $i = $this->invitation($token);
        return $i ? $this->json(['client_name' => $i['client_name'], 'email' => $i['email']]) : $this->gone();
    }

    #[Route('/client-invite/{token}', methods: ['POST'])]
    public function accept(string $token, Request $r): JsonResponse
    {
        $b = json_decode($r->getContent(), true);
        $password = is_array($b) ? (string) ($b['password'] ?? '') : '';
        if (mb_strlen($password) < 10) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Mot de passe : 10 caractères minimum.',
                                'violations' => ['password' => '10 caractères minimum.']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->db->beginTransaction();
        try {
            $i = $this->invitation($token);
            if (!$i) {
                $this->db->rollBack();
                return $this->gone();
            }
            // Marque l'invitation comme utilisée d'abord : un second appel simultané n'aboutira pas
            $used = $this->db->executeStatement(
                'UPDATE client_invitations SET accepted_at = UTC_TIMESTAMP() WHERE id = ? AND accepted_at IS NULL', [$i['id']]
            );
            if ($used !== 1) {
                $this->db->rollBack();
                return $this->gone();
            }
            $email = mb_strtolower($i['email']);
            if ($this->db->fetchOne('SELECT 1 FROM client_users WHERE login = ?', ['client:' . $email])) {
                $this->db->rollBack();
                return $this->json(['error' => 'exists', 'message' => 'Un compte existe déjà pour cet email.'], Response::HTTP_CONFLICT);
            }
            $user = new ClientUser((int) $i['client_id'], $email);
            $user->setPasswordHash($this->hasher->hashPassword($user, $password));
            $this->db->insert('client_users', [
                'client_id' => $i['client_id'], 'email' => $email,
                'login' => $user->getUserIdentifier(), 'password_hash' => $user->getPassword(),
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->json(['ok' => true], Response::HTTP_CREATED);
    }

    /** Connexion client : jeton JWT séparé, valable seulement sur /api/portal. */
    #[Route('/portal/login', methods: ['POST'])]
    public function login(Request $r): JsonResponse
    {
        $b = json_decode($r->getContent(), true);
        $email = mb_strtolower(trim((string) (is_array($b) ? ($b['email'] ?? '') : '')));
        $password = (string) (is_array($b) ? ($b['password'] ?? '') : '');

        $row = $this->db->fetchAssociative('SELECT * FROM client_users WHERE login = ? AND is_active = 1', ['client:' . $email]);
        $user = null;
        if ($row) {
            $user = new ClientUser((int) $row['client_id'], $row['email']);
            $user->setPasswordHash($row['password_hash']);
        }
        // Même réponse pour « inconnu » et « mauvais mot de passe »
        if (!$user || !$this->hasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'bad_credentials', 'message' => 'Email ou mot de passe incorrect.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['token' => $this->jwt->create($user)]);
    }
}
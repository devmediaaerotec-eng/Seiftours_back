<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Formulaire de contact du site : le message arrive dans l'administration (table contact_messages). */
final class PublicContactController extends AbstractController
{
    private const SUBJECTS = ['general', 'hotels', 'omra', 'trips', 'flights', 'circuits', 'transport'];
    private const HOURLY_LIMIT = 5;

    public function __construct(private Connection $db) {}

    #[Route('/api/public/contact-messages', methods: ['POST'])]
    public function create(Request $r): JsonResponse
    {
        $in = json_decode($r->getContent(), true);
        if (!is_array($in)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (trim((string) ($in['website'] ?? '')) !== '') {                 // piège à robots : faux succès, rien d'enregistré
            return $this->json(['ok' => true], Response::HTTP_CREATED);
        }

        $ip = $r->getClientIp();
        if ($ip && (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM contact_messages WHERE ip_address = ? AND created_at > (CURRENT_TIMESTAMP(3) - INTERVAL 1 HOUR)', [$ip]
            ) >= self::HOURLY_LIMIT) {
            return $this->json(['error' => 'too_many', 'message' => 'Trop de messages envoyés. Réessayez dans une heure ou appelez-nous.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $e = [];
        $str = fn (string $k, int $max): ?string => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        $name = $str('name', 120);
        if ($name === null || mb_strlen($name) < 2) { $e['name'] = 'Veuillez indiquer votre nom.'; }

        $phone = $str('phone', 30);
        if ($phone !== null && (!preg_match('/^\+?[0-9][0-9 .\-()]{6,28}$/', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 8)) {
            $e['phone'] = 'Numéro de téléphone invalide.';
        }
        $email = $str('email', 190);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $e['email'] = 'Adresse e-mail invalide.'; }
        if ($phone === null && $email === null) { $e['contact'] = 'Indiquez un numéro de téléphone ou un e-mail valide.'; }

        $message = $str('message', 2000);
        if ($message === null || mb_strlen($message) < 10) { $e['message'] = 'Votre message est trop court (10 caractères minimum).'; }

        $subject = (string) ($in['subject'] ?? 'general');
        if (!in_array($subject, self::SUBJECTS, true)) { $subject = 'general'; }

        if ($e) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.', 'violations' => $e], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $row = ['name' => $name, 'phone' => $phone, 'email' => $email, 'subject' => $subject, 'message' => $message, 'ip_address' => $ip];
        $this->db->insert('contact_messages', $row);

        try {   // prévient l'agence si une adresse est configurée ; le message est déjà enregistré, donc échec silencieux
            $to = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'contact_notify_email'");
            $to = $to ? json_decode((string) $to, true) : null;
            if (is_string($to) && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $this->db->insert('notifications', [
                    'channel' => 'email', 'recipient' => $to, 'template' => 'contact_new',
                    'payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
                ]);
            }
        } catch (\Throwable) {
        }

        return $this->json(['ok' => true], Response::HTTP_CREATED);
    }
}

<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Réservation / demande de devis sur un voyage organisé, une Omra ou un circuit. Enregistrée dans trip_requests. */
final class PublicTripRequestController extends AbstractController
{
    private const HOURLY_LIMIT = 6;

    public function __construct(private Connection $db) {}

    #[Route('/api/public/trip-requests', methods: ['POST'])]
    public function create(Request $r): JsonResponse
    {
        $in = json_decode($r->getContent(), true);
        if (!is_array($in)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (trim((string) ($in['website'] ?? '')) !== '') {          // piège à robots : faux succès, rien d'enregistré
            return $this->json(['ok' => true, 'reference' => 'RV' . strtoupper(bin2hex(random_bytes(4)))], Response::HTTP_CREATED);
        }

        $ip = $r->getClientIp();
        if ($ip && (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM trip_requests WHERE ip_address = ? AND created_at > (CURRENT_TIMESTAMP(3) - INTERVAL 1 HOUR)', [$ip]
            ) >= self::HOURLY_LIMIT) {
            return $this->json(['error' => 'too_many', 'message' => 'Trop de demandes. Réessayez dans une heure ou appelez-nous.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $e = [];
        $str = fn (string $k, int $max): ?string => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        $trip = is_numeric($in['trip_id'] ?? null)
            ? $this->db->fetchAssociative('SELECT id, title, type FROM trips WHERE id = ? AND is_active = 1', [(int) $in['trip_id']]) : false;
        if (!$trip) {
            return $this->json(['error' => 'trip_not_found', 'message' => "Ce voyage n'est plus disponible."], Response::HTTP_NOT_FOUND);
        }

        $name = $str('name', 160);
        if ($name === null || mb_strlen($name) < 2) { $e['name'] = 'Indiquez votre nom.'; }
        $phone = $str('phone', 30);
        if ($phone === null || !preg_match('/^\+?[0-9][0-9 .\-()]{6,24}$/', $phone)) { $e['phone'] = 'Numéro de téléphone invalide.'; }
        $email = $str('email', 190);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $e['email'] = 'Adresse e-mail invalide.'; }

        $adults = $in['adults'] ?? null;
        if (!is_numeric($adults) || (int) $adults < 1 || (int) $adults > 20) { $e['adults'] = 'Entre 1 et 20 adultes.'; }
        $children = $in['children'] ?? 0;
        if (!is_numeric($children) || (int) $children < 0 || (int) $children > 20) { $e['children'] = 'Entre 0 et 20 enfants.'; }

        $date = $str('preferred_date', 10);
        if ($date !== null) {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$dt || $dt->format('Y-m-d') !== $date) { $e['preferred_date'] = 'Date invalide.'; $date = null; }
        }

        if ($e) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.', 'violations' => $e], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // La date choisie correspond-elle à un départ publié ? Sinon on garde seulement la date souhaitée.
        $departureId = $date ? $this->db->fetchOne(
            'SELECT id FROM trip_departures WHERE trip_id = ? AND departure_date = ? AND is_active = 1 LIMIT 1', [$trip['id'], $date]) : null;

        $reference = 'RV' . strtoupper(bin2hex(random_bytes(4)));
        $row = [
            'reference' => $reference, 'trip_id' => (int) $trip['id'], 'departure_id' => $departureId ?: null, 'preferred_date' => $date,
            'adults' => (int) $adults, 'children' => (int) $children, 'client_name' => $name, 'client_email' => $email,
            'client_phone' => $phone, 'message' => $str('message', 3000), 'ip_address' => $ip,
        ];
        $this->db->insert('trip_requests', $row);

        try {   // prévient l'agence ; la demande est déjà enregistrée, donc échec silencieux
            $to = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'trip_request_notify_email'");
            $to = $to ? json_decode((string) $to, true) : null;
            if (is_string($to) && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $this->db->insert('notifications', [
                    'channel' => 'email', 'recipient' => $to, 'template' => 'trip_request_new',
                    'payload' => json_encode(['trip' => $trip['title'], 'type' => $trip['type']] + $row, JSON_UNESCAPED_UNICODE),
                ]);
            }
        } catch (\Throwable) {
        }

        return $this->json(['ok' => true, 'reference' => $reference], Response::HTTP_CREATED);
    }
}

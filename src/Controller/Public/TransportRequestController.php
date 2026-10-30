<?php
namespace App\Controller\Public;

use App\Service\MissionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TransportRequestController extends AbstractController
{
    private const VEHICLES = ['any', 'minibus', 'microbus'];
    private const PURPOSES = ['wedding', 'excursion', 'airport', 'school', 'seminar', 'circuit', 'other'];

    public function __construct(private Connection $db, private MissionService $missions) {}

    #[Route('/api/public/transport-requests', methods: ['POST'])]
    public function __invoke(Request $r): JsonResponse
    {
        $b = json_decode($r->getContent(), true);
        if (!is_array($b)) {
            return $this->json(['error' => 'invalid_json', 'message' => 'Requête invalide.'], Response::HTTP_BAD_REQUEST);
        }

        // Champ piège invisible pour les humains : un robot le remplit, on fait semblant d'accepter
        if (trim((string) ($b['website'] ?? '')) !== '') {
            return $this->json(['reference' => 'TR' . strtoupper(bin2hex(random_bytes(4)))], Response::HTTP_CREATED);
        }

        // Au plus 5 demandes par adresse IP et par heure
        $ip = $r->getClientIp();
        if ($ip && (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM transport_requests WHERE ip_address = ? AND created_at > UTC_TIMESTAMP(3) - INTERVAL 1 HOUR', [$ip]
        ) >= 5) {
            return $this->json(['error' => 'too_many_requests',
                'message' => 'Vous avez envoyé plusieurs demandes en peu de temps. Réessayez plus tard ou appelez-nous.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $errors = [];
        $text = function (string $key, int $max, bool $required) use ($b, &$errors): ?string {
            $v = trim((string) ($b[$key] ?? ''));
            if ($v === '') {
                if ($required) {
                    $errors[$key] = 'Champ requis.';
                }
                return null;
            }
            if (mb_strlen($v) > $max) {
                $errors[$key] = "$max caractères maximum.";
                return null;
            }
            return $v;
        };

        $vehicle = $b['vehicle_pref'] ?? 'any';
        if (!in_array($vehicle, self::VEHICLES, true)) {
            $errors['vehicle_pref'] = 'Type de véhicule invalide.';
        }
        $purpose = $b['purpose'] ?? 'other';
        if (!in_array($purpose, self::PURPOSES, true)) {
            $errors['purpose'] = 'Choix invalide.';
        }
        $pax = filter_var($b['pax'] ?? null, FILTER_VALIDATE_INT);
        if ($pax === false || $pax < 1 || $pax > 90) {
            $errors['pax'] = 'Indiquez un nombre de passagers entre 1 et 90.';
        }

        $pickup = $text('pickup_place', 190, true);
        $dest = $text('destination', 190, true);
        $name = $text('client_name', 160, true);
        $message = $text('message', 2000, false);

        $phone = $this->phone((string) ($b['client_phone'] ?? ''));
        if ($phone === null) {
            $errors['client_phone'] = 'Numéro de téléphone invalide.';
        }
        $email = $text('client_email', 190, false);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['client_email'] = 'Adresse e-mail invalide.';
        }

        $start = $this->missions->parseLocal($b['starts_at'] ?? null);
        $end = $this->missions->parseLocal($b['ends_at'] ?? null);
        if (!$start) {
            $errors['starts_at'] = 'Date et heure de départ invalides.';
        } elseif ($start->format('Y-m-d H:i:s') <= $this->missions->nowLocal()) {
            $errors['starts_at'] = 'Le départ doit être dans le futur.';
        }
        if (!$end) {
            $errors['ends_at'] = "Date et heure d'arrivée invalides.";
        } elseif ($start && $end <= $start) {
            $errors['ends_at'] = "L'arrivée doit être après le départ.";
        } elseif ($start && $end > $start->modify('+90 days')) {
            $errors['ends_at'] = 'Durée maximale : 90 jours. Contactez-nous pour une demande plus longue.';
        }

        if ($errors) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Certains champs sont invalides.', 'violations' => $errors],
                Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $reference = $this->newReference();
        $this->db->insert('transport_requests', [
            'reference' => $reference, 'vehicle_pref' => $vehicle, 'purpose' => $purpose, 'pax' => $pax,
            'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'),
            'pickup_place' => $pickup, 'destination' => $dest, 'client_name' => $name, 'client_phone' => $phone,
            'client_email' => $email, 'message' => $message, 'ip_address' => $ip,
        ]);

        return $this->json(['reference' => $reference, 'status' => 'new'], Response::HTTP_CREATED);
    }

    private function phone(string $raw): ?string
    {
        $p = preg_replace('/[\s.\-()]/', '', $raw);
        if (preg_match('/^\d{8}$/', $p)) {
            $p = '+216' . $p;
        } elseif (str_starts_with($p, '00')) {
            $p = '+' . substr($p, 2);
        }
        return preg_match('/^\+[0-9]{8,15}$/', $p) ? $p : null;
    }

    private function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $ref = 'TR';
            for ($i = 0; $i < 8; $i++) {
                $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($this->db->fetchOne('SELECT 1 FROM transport_requests WHERE reference = ?', [$ref]));
        return $ref;
    }
}
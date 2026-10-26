<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Voyages visibles sur le site public. Aucune authentification, lecture seule.
 * Seuls les voyages publiés (is_active = 1) sortent d'ici ; les prix sont en DINARS (millimes / 1000).
 */
#[Route('/api/public/trips')]
final class PublicTripController extends AbstractController
{
    private const TYPES = ['omra', 'organized', 'circuit'];
    private const LANGS = ['en', 'ar'];

    public function __construct(private Connection $db) {}

    /** GET /api/public/trips?type=organized&destination=istanbul&region=sud&lang=en */
    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $where  = ['t.is_active = 1'];
        $params = [];

        $type = $r->query->getString('type');
        if (in_array($type, self::TYPES, true)) {
            $where[] = 't.type = ?';
            $params[] = $type;
        }
        if (($dest = strtolower(trim($r->query->getString('destination')))) !== '') {   // "Bali" == "bali"
            $where[] = 'd.slug = ?';
            $params[] = $dest;
        }
        if (($region = strtolower(trim($r->query->getString('region')))) !== '') {
            $where[] = 'rg.slug = ?';
            $params[] = $region;
        }
        $lang = $this->lang($r);

        $rows = $this->db->fetchAllAssociative(
            'SELECT t.id, t.type, t.slug, t.title, t.summary, t.image_url, t.duration_nights, t.departure_city,
                    t.price_from_millimes, t.old_price_millimes, t.currency, t.includes, t.is_featured, t.i18n, t.attributes,
                    d.name AS destination_name, d.i18n AS destination_i18n,
                    rg.name AS region_name, rg.i18n AS region_i18n
             FROM trips t
             LEFT JOIN destinations d     ON d.id  = t.destination_id
             LEFT JOIN circuit_regions rg ON rg.id = t.region_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.is_featured DESC, t.sort_order, t.id DESC',
            $params
        );

        // Départs à venir de tous ces voyages en UNE requête (pas de N+1)
        $byTrip = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $deps = $this->db->fetchAllAssociative(
                'SELECT trip_id, departure_date, price_per_person_millimes
                 FROM trip_departures
                 WHERE trip_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                   AND is_active = 1 AND departure_date >= CURDATE()
                   AND (seats_left IS NULL OR seats_left > 0)
                 ORDER BY departure_date',
                $ids
            );
            foreach ($deps as $x) {
                $byTrip[$x['trip_id']][] = $x;
            }
        }

        $base = $r->getSchemeAndHttpHost();
        return $this->json(['items' => array_map(fn ($t) => $this->card($t, $byTrip[$t['id']] ?? [], $lang, $base), $rows)]);
    }

    /** GET /api/public/trips/{slug} : fiche complète (programme, galerie, départs) */
    #[Route('/{slug}', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(string $slug, Request $r): JsonResponse
    {
        $t = $this->db->fetchAssociative(
            'SELECT t.*, d.name AS destination_name, rg.name AS region_name
             FROM trips t
             LEFT JOIN destinations d     ON d.id  = t.destination_id
             LEFT JOIN circuit_regions rg ON rg.id = t.region_id
             WHERE t.slug = ? AND t.is_active = 1', [$slug]);
        if (!$t) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $lang = $this->lang($r);
        $base = $r->getSchemeAndHttpHost();
        $tr   = $this->tr($t['i18n'], $lang);

        $days = $this->db->fetchAllAssociative(
            'SELECT day_number, title, description, i18n FROM trip_days WHERE trip_id = ? ORDER BY day_number', [$t['id']]);
        $days = array_map(fn ($d) => [
            'day_number'  => (int) $d['day_number'],
            'title'       => $this->tr($d['i18n'], $lang)['title'] ?? $d['title'],
            'description' => $this->tr($d['i18n'], $lang)['description'] ?? $d['description'],
        ], $days);

        $images = $this->db->fetchAllAssociative(
            'SELECT url, alt FROM trip_images WHERE trip_id = ? ORDER BY sort_order, id', [$t['id']]);
        $deps = $this->db->fetchAllAssociative(
            'SELECT id, departure_date, return_date, price_per_person_millimes, single_supplement_millimes,
                    child_price_millimes, seats_left
             FROM trip_departures
             WHERE trip_id = ? AND is_active = 1 AND departure_date >= CURDATE()
             ORDER BY departure_date', [$t['id']]);

        return $this->json([
            'id'             => (int) $t['id'],
            'type'           => $t['type'],
            'slug'           => $t['slug'],
            'title'          => $tr['title'] ?? $t['title'],
            'summary'        => $tr['summary'] ?? $t['summary'],
            'description'    => $tr['description'] ?? $t['description'],
            'destination'    => $t['destination_name'] ?? $t['region_name'],
            'departure_city' => $t['departure_city'],
            'duration_nights'=> (int) $t['duration_nights'],
            'image_url'      => $this->abs($t['image_url'], $base),
            'currency'       => $t['currency'],
            'new_price'      => $this->tnd($t['price_from_millimes']),
            'old_price'      => $this->tnd($t['old_price_millimes']),
            'inclusions'     => $this->lines($tr['includes'] ?? $t['includes']),
            'exclusions'     => $this->lines($tr['excludes'] ?? $t['excludes']),
            'attributes'     => $this->attrs($t['attributes'] ?? null),
            'days'           => $days,
            'images'         => array_map(fn ($i) => ['url' => $this->abs($i['url'], $base), 'alt' => $i['alt']], $images),
            'departures'     => array_map(fn ($x) => [
                'id'             => (int) $x['id'],
                'departure_date' => $x['departure_date'],
                'return_date'    => $x['return_date'],
                'price'          => $this->tnd($x['price_per_person_millimes']),
                'single_supplement' => $this->tnd($x['single_supplement_millimes']),
                'child_price'    => $this->tnd($x['child_price_millimes']),
                'seats_left'     => $x['seats_left'] === null ? null : (int) $x['seats_left'],
            ], $deps),
        ]);
    }

    // ------------------------------------------------------------------

    private function card(array $t, array $deps, string $lang, string $base): array
    {
        $tr    = $this->tr($t['i18n'], $lang);
        $place = $t['destination_name'] ?? $t['region_name'];
        $trans = $this->tr($t['destination_i18n'] ?? $t['region_i18n'] ?? null, $lang);
        $new   = $this->tnd($t['price_from_millimes']);
        $old   = $this->tnd($t['old_price_millimes']);
        $nights = (int) $t['duration_nights'];

        return [
            'id'              => (int) $t['id'],
            'slug'            => $t['slug'],
            'type'            => $t['type'],
            'title'           => $tr['title'] ?? $t['title'],
            'summary'         => $tr['summary'] ?? $t['summary'],
            'destination'     => $trans['name'] ?? $place,
            'image_url'       => $this->abs($t['image_url'], $base),
            'duration_nights' => $nights,
            'duration'        => $nights . ' nuit' . ($nights > 1 ? 's' : ''),
            'currency'        => $t['currency'],
            'new_price'       => $new ?? ($deps ? $this->tnd(min(array_column($deps, 'price_per_person_millimes'))) : null),
            'old_price'       => $old,
            'inclusions'      => $this->lines($tr['includes'] ?? $t['includes']),
            'departure_dates' => array_column($deps, 'departure_date'),    // "2026-12-01"
            'is_featured'     => (bool) $t['is_featured'],
            'attributes'      => $this->attrs($t['attributes'] ?? null),
            'badge'           => $old && $new && $old > $new ? '-' . (int) round((1 - $new / $old) * 100) . '%' : null,
        ];
    }

    private function tnd(mixed $millimes): int|float|null
    {
        if ($millimes === null) {
            return null;
        }
        $v = (int) $millimes / 1000;
        return $v == (int) $v ? (int) $v : $v;
    }

    /** Une ligne = un élément (même convention que le formulaire d'administration). */
    private function lines(?string $text): array
    {
        return $text ? array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)))) : [];
    }

    /** Les envois d'administration sont stockés en "/uploads/…" : le site (autre domaine) a besoin d'une URL complète. */
    private function abs(?string $url, string $base): ?string
    {
        return $url !== null && $url !== '' && str_starts_with($url, '/') && !str_starts_with($url, '//') ? $base . $url : ($url ?: null);
    }

    /** Champs spécifiques (circuit : étapes...; omra : hôtels...) : clés connues seulement, toujours un objet JSON. */
    private function attrs(?string $json): object
    {
        $a = json_decode($json ?: '{}', true);
        return (object) (is_array($a)
            ? array_intersect_key($a, array_flip(['stops', 'highlights', 'group_size', 'hotel_makkah', 'hotel_madinah', 'distance']))
            : []);
    }

    private function lang(Request $r): string
    {
        $l = $r->query->getString('lang');
        return in_array($l, self::LANGS, true) ? $l : 'fr';
    }

    /** Traductions d'une ligne i18n pour la langue demandée ([] pour le français : les colonnes normales font foi). */
    private function tr(?string $json, string $lang): array
    {
        if ($lang === 'fr' || !$json) {
            return [];
        }
        $all = json_decode($json, true);
        return is_array($all) && is_array($all[$lang] ?? null) ? $all[$lang] : [];
    }
}

<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Sections de la page d'accueil : tuiles « catégories » (chiffres) et « destinations ». */
final class HomeController extends AbstractController
{
    private const LANGS = ['en', 'ar'];
    // prix affiché d'un voyage : son prix « à partir de », sinon le départ à venir le moins cher
    private const PRICE = "COALESCE(t.price_from_millimes, (SELECT MIN(x.price_per_person_millimes) FROM trip_departures x
                              WHERE x.trip_id = t.id AND x.is_active = 1 AND x.departure_date >= CURDATE()))";

    public function __construct(private Connection $db) {}

    /** Nombre d'offres publiées et prix de départ (TND) par rubrique. Une rubrique vide renvoie count = 0. */
    #[Route('/api/public/home/categories', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        $out = [];
        foreach (['circuit' => 'circuits', 'organized' => 'organized', 'omra' => 'omra'] as $_ => $key) {
            $out[$key] = ['count' => 0, 'from' => null];
        }
        foreach ($this->db->fetchAllAssociative(
            "SELECT t.type, COUNT(*) AS n,
                    MIN(CASE WHEN t.currency = 'TND' THEN " . self::PRICE . " END) AS cheapest
             FROM trips t WHERE t.is_active = 1 GROUP BY t.type") as $r) {
            $key = $r['type'] === 'circuit' ? 'circuits' : $r['type'];
            $out[$key] = ['count' => (int) $r['n'], 'from' => $r['cheapest'] === null ? null : (int) $r['cheapest'] / 1000];
        }
        $out['hotels'] = ['count' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM hotels WHERE is_visible = 1'), 'from' => null];

        return $this->cached($this->json($out), 120);
    }

    /** Destinations à l'étranger ayant au moins un voyage publié (une destination vide mènerait à une page vide). */
    #[Route('/api/public/home/destinations', methods: ['GET'])]
    public function destinations(Request $r): JsonResponse
    {
        $lang  = $r->query->getString('lang');
        $lang  = in_array($lang, self::LANGS, true) ? $lang : 'fr';
        $limit = max(1, min(8, $r->query->getInt('limit', 4)));
        $base  = $r->getSchemeAndHttpHost();

        $rows = $this->db->fetchAllAssociative(
            "SELECT d.slug, d.name, d.country, d.tagline, d.image_url, d.i18n,
                    COUNT(t.id) AS tours,
                    MIN(CASE WHEN t.currency = 'TND' THEN " . self::PRICE . " END) AS cheapest,
                    MAX(t.is_featured) AS featured,
                    (SELECT t2.image_url FROM trips t2
                      WHERE t2.destination_id = d.id AND t2.is_active = 1 AND t2.image_url IS NOT NULL
                      ORDER BY t2.is_featured DESC, t2.sort_order, t2.id LIMIT 1) AS trip_image
             FROM destinations d
             JOIN trips t ON t.destination_id = d.id AND t.is_active = 1
             WHERE d.is_active = 1 AND d.kind = 'abroad'
             GROUP BY d.id
             ORDER BY featured DESC, d.sort_order, d.name
             LIMIT $limit");

        $items = [];
        foreach ($rows as $i => $d) {
            $tr  = $lang !== 'fr' ? (json_decode($d['i18n'] ?: '{}', true)[$lang] ?? []) : [];
            $img = $d['image_url'] ?: $d['trip_image'];
            if ($img && str_starts_with($img, '/') && !str_starts_with($img, '//')) {
                $img = $base . $img;
            }
            $items[] = [
                'slug'     => $d['slug'],
                'name'     => $tr['name'] ?? $d['name'],
                'country'  => $d['country'],
                'tagline'  => $tr['tagline'] ?? $d['tagline'],
                'image_url'=> $img ?: null,
                'tours'    => (int) $d['tours'],
                'from'     => $d['cheapest'] === null ? null : (int) $d['cheapest'] / 1000,
                'featured' => $i === 0 && (bool) $d['featured'],      // la « coup de cœur » est la première
            ];
        }

        return $this->cached($this->json(['items' => $items]), 120);
    }

    /**
     * Chiffres clés (section « Pourquoi nous choisir »). Destinations / voyages / hôtels : comptés en base.
     * Clients et années d'expérience : réglages modifiables (settings.stat_clients, settings.stat_experience_years).
     */
    #[Route('/api/public/home/stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $setting = function (string $key): ?string {
            $raw = $this->db->fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
            if ($raw === false || $raw === null) {
                return null;
            }
            $v = json_decode((string) $raw, true);
            return is_string($v) || is_int($v) || is_float($v) ? (string) $v : null;
        };

        return $this->cached($this->json([
            'destinations' => (int) $this->db->fetchOne('SELECT COUNT(DISTINCT destination_id) FROM trips WHERE is_active = 1 AND destination_id IS NOT NULL'),
            'trips'        => (int) $this->db->fetchOne('SELECT COUNT(*) FROM trips WHERE is_active = 1'),
            'hotels'       => (int) $this->db->fetchOne('SELECT COUNT(*) FROM hotels WHERE is_visible = 1'),
            'clients'      => $setting('stat_clients'),
            'years'        => $setting('stat_experience_years'),
        ]), 300);
    }

    private function cached(JsonResponse $r, int $seconds): JsonResponse
    {
        return $r->setPublic()->setMaxAge($seconds);
    }
}
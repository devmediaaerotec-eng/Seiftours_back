<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Slider « Best Seller » de l'accueil.
 * Sources : bons plans mis à la une et en cours de validité, puis voyages mis à la une.
 * Pilotage par l'administration : « À la une » (is_featured) + « Actif » + dates de validité.
 */
final class BestSellerController extends AbstractController
{
    private const LANGS = ['en', 'ar'];
    private const TRIP_PATH = ['organized' => '/voyage-organise', 'omra' => '/omra', 'circuit' => '/circuit'];

    public function __construct(private Connection $db) {}

    #[Route('/api/public/best-sellers', methods: ['GET'])]
    public function __invoke(Request $r): JsonResponse
    {
        $lang  = $r->query->getString('lang');
        $lang  = in_array($lang, self::LANGS, true) ? $lang : 'fr';
        $limit = max(1, min(10, $r->query->getInt('limit', 6)));
        $base  = $r->getSchemeAndHttpHost();

        $items = [];
        $usedTrips = [];

        // 1. Bons plans à la une (hôtel ou voyage), valides aujourd'hui
        $deals = $this->db->fetchAllAssociative(
            "SELECT dl.id, dl.title, dl.discount_text, dl.old_price_millimes, dl.price_millimes, dl.currency,
                    dl.image_url AS deal_image, dl.i18n AS deal_i18n, dc.name AS category_name, dc.i18n AS cat_i18n,
                    t.id AS trip_id, t.type AS trip_type, t.slug AS trip_slug, t.image_url AS trip_image,
                    t.duration_nights, t.price_from_millimes AS trip_price, t.old_price_millimes AS trip_old,
                    td.name AS trip_dest, trg.name AS trip_region,
                    h.supplier_hotel_id, h.city AS hotel_city, h.main_image_url
             FROM deals dl
             JOIN deal_categories dc ON dc.id = dl.category_id AND dc.is_active = 1
             LEFT JOIN trips t ON t.id = dl.trip_id AND t.is_active = 1
             LEFT JOIN destinations td ON td.id = t.destination_id
             LEFT JOIN circuit_regions trg ON trg.id = t.region_id
             LEFT JOIN hotels h ON h.id = dl.hotel_id AND h.is_visible = 1
             WHERE dl.is_active = 1 AND dl.is_featured = 1
               AND (dl.valid_from IS NULL OR dl.valid_from <= CURDATE())
               AND (dl.valid_until IS NULL OR dl.valid_until >= CURDATE())
               AND (dl.trip_id IS NULL OR t.id IS NOT NULL)
               AND (dl.hotel_id IS NULL OR h.id IS NOT NULL)
             ORDER BY dl.sort_order, dl.id DESC
             LIMIT $limit");

        foreach ($deals as $d) {
            $tr = $this->tr($d['deal_i18n'], $lang);
            if ($d['trip_id']) {
                $usedTrips[] = (int) $d['trip_id'];
                $kind = $d['trip_type'];
                $to   = (self::TRIP_PATH[$kind] ?? '/voyage-organise') . '/' . $d['trip_slug'];
                $loc  = $d['trip_dest'] ?? $d['trip_region'];
                $nights = (int) $d['duration_nights'];
                $price = $d['price_millimes'] ?? $d['trip_price'];
                $old   = $d['old_price_millimes'] ?? $d['trip_old'];
                $img   = $d['deal_image'] ?: $d['trip_image'];
            } else {
                $kind = 'hotel';
                $to   = '/hotels/' . $d['supplier_hotel_id'];
                $loc  = $d['hotel_city'];
                $nights = null;
                $price = $d['price_millimes'];
                $old   = $d['old_price_millimes'];
                $img   = $d['deal_image'] ?: $d['main_image_url'];
            }
            $items[] = $this->item('deal-' . $d['id'], $kind, $this->tr($d['cat_i18n'], $lang)['name'] ?? $d['category_name'],
                $tr['title'] ?? $d['title'], $loc, $img, $price, $old, $d['currency'], $nights, $d['discount_text'], $to, $base);
        }

        // 2. Complète avec les voyages mis à la une (sans doublon avec les bons plans)
        if (count($items) < $limit) {
            $not = $usedTrips ? ' AND t.id NOT IN (' . implode(',', array_map('intval', $usedTrips)) . ')' : '';
            $trips = $this->db->fetchAllAssociative(
                "SELECT t.id, t.type, t.slug, t.title, t.i18n, t.image_url, t.duration_nights, t.price_from_millimes,
                        t.old_price_millimes, t.currency, td.name AS dest, trg.name AS region
                 FROM trips t
                 LEFT JOIN destinations td ON td.id = t.destination_id
                 LEFT JOIN circuit_regions trg ON trg.id = t.region_id
                 WHERE t.is_active = 1 AND t.is_featured = 1 AND t.price_from_millimes IS NOT NULL $not
                 ORDER BY t.sort_order, t.id DESC
                 LIMIT " . ($limit - count($items)));
            foreach ($trips as $t) {
                $tr = $this->tr($t['i18n'], $lang);
                $items[] = $this->item('trip-' . $t['id'], $t['type'], null, $tr['title'] ?? $t['title'], $t['dest'] ?? $t['region'],
                    $t['image_url'], $t['price_from_millimes'], $t['old_price_millimes'], $t['currency'], (int) $t['duration_nights'],
                    null, (self::TRIP_PATH[$t['type']] ?? '/voyage-organise') . '/' . $t['slug'], $base);
            }
        }

        $res = $this->json(['items' => $items]);
        $res->setPublic()->setMaxAge(60);

        return $res;
    }

    private function item(string $id, string $kind, ?string $category, string $title, ?string $location, ?string $image,
                          mixed $price, mixed $old, string $currency, ?int $nights, ?string $discount, string $to, string $base): array
    {
        $p = $price === null ? null : (int) $price / 1000;
        $o = $old === null ? null : (int) $old / 1000;
        if (!$discount && $p && $o && $o > $p) {
            $discount = '-' . (int) round((1 - $p / $o) * 100) . '%';
        }
        if ($image && str_starts_with($image, '/') && !str_starts_with($image, '//')) {
            $image = $base . $image;        // envois d'administration : URL complète pour le site
        }

        return [
            'id' => $id, 'kind' => $kind, 'category' => $category, 'title' => $title, 'location' => $location,
            'image_url' => $image ?: null, 'price' => $p, 'old_price' => $o && $p && $o > $p ? $o : null,
            'currency' => $currency, 'nights' => $nights ?: null, 'discount' => $discount ?: null, 'to' => $to,
        ];
    }

    private function tr(?string $json, string $lang): array
    {
        if ($lang === 'fr' || !$json) {
            return [];
        }
        $all = json_decode($json, true);
        return is_array($all) && is_array($all[$lang] ?? null) ? $all[$lang] : [];
    }
}
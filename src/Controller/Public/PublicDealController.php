<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Page « Bons plans » : offres actives et en cours de validité, avec leurs catégories. */
final class PublicDealController extends AbstractController
{
    private const LANGS = ['en', 'ar'];
    private const TRIP_PATH = ['organized' => '/voyage-organise', 'omra' => '/omra', 'circuit' => '/circuit'];

    public function __construct(private Connection $db) {}

    #[Route('/api/public/deals', methods: ['GET'])]
    public function __invoke(Request $r): JsonResponse
    {
        $lang = $r->query->getString('lang');
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'fr';
        $base = $r->getSchemeAndHttpHost();

        $rows = $this->db->fetchAllAssociative(self::SELECT . "
             WHERE dl.is_active = 1" . self::LIVE . "
             ORDER BY dl.is_featured DESC, dl.sort_order, dl.id DESC
             LIMIT 60");

        $items = [];
        $cats = [];
        $updated = null;
        foreach ($rows as $d) {
            $item = $this->build($d, $lang, $base);
            $cats[$d['cat_slug']] ??= ['slug' => $d['cat_slug'], 'name' => $item['category_name'], 'count' => 0, 'order' => (int) $d['cat_order']];
            $cats[$d['cat_slug']]['count']++;
            $updated = max($updated ?? '', $d['updated_at'] ?? '');
            unset($item['target'], $item['trip_to'], $item['conditions']);
            $items[] = $item;
        }
        usort($cats, fn ($a, $b) => $a['order'] <=> $b['order'] ?: strcmp($a['name'], $b['name']));

        $res = $this->json([
            'categories' => array_map(fn ($c) => ['slug' => $c['slug'], 'name' => $c['name'], 'count' => $c['count']], $cats),
            'items'      => $items,
            'updated_at' => $updated ? substr($updated, 0, 10) : null,
        ]);
        $res->setPublic()->setMaxAge(60);

        return $res;
    }

    /** Fiche d'un bon plan : /bons-plans/{id} */
    #[Route('/api/public/deals/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $r): JsonResponse
    {
        $lang = $r->query->getString('lang');
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'fr';
        $d = $this->db->fetchAssociative(self::SELECT . "
             WHERE dl.id = :id AND dl.is_active = 1" . self::LIVE, ['id' => $id]);
        if (!$d) {
            return $this->json(['error' => 'not_found', 'message' => 'Offre introuvable ou expirée.'], 404);
        }
        $res = $this->json($this->build($d, $lang, $r->getSchemeAndHttpHost()));
        $res->setPublic()->setMaxAge(60);

        return $res;
    }

    private const SELECT = "SELECT dl.id, dl.title, dl.description, dl.discount_text, dl.old_price_millimes, dl.price_millimes, dl.currency,
                    dl.image_url AS deal_image, dl.link_url, dl.valid_until, dl.is_featured, dl.i18n AS deal_i18n, dl.updated_at,
                    dc.slug AS cat_slug, dc.name AS cat_name, dc.i18n AS cat_i18n, dc.sort_order AS cat_order,
                    t.type AS trip_type, t.slug AS trip_slug, t.image_url AS trip_image, t.duration_nights,
                    t.price_from_millimes AS trip_price, t.old_price_millimes AS trip_old,
                    td.name AS trip_dest, trg.name AS trip_region,
                    h.supplier_hotel_id, h.city AS hotel_city, h.main_image_url
             FROM deals dl
             JOIN deal_categories dc ON dc.id = dl.category_id AND dc.is_active = 1
             LEFT JOIN trips t ON t.id = dl.trip_id AND t.is_active = 1
             LEFT JOIN destinations td ON td.id = t.destination_id
             LEFT JOIN circuit_regions trg ON trg.id = t.region_id
             LEFT JOIN hotels h ON h.id = dl.hotel_id AND h.is_visible = 1";

    private const LIVE = "
               AND (dl.valid_from IS NULL OR dl.valid_from <= CURDATE())
               AND (dl.valid_until IS NULL OR dl.valid_until >= CURDATE())
               AND (dl.trip_id IS NULL OR t.id IS NOT NULL)
               AND (dl.hotel_id IS NULL OR h.id IS NOT NULL)";

    private function build(array $d, string $lang, string $base): array
    {
        $tr = $this->tr($d['deal_i18n'], $lang);
        if ($d['trip_type']) {
            $tripTo = (self::TRIP_PATH[$d['trip_type']] ?? '/voyage-organise') . '/' . $d['trip_slug'];
            $target = '/reserver/' . $d['trip_slug'];       // réserver ce voyage
            $loc    = $d['trip_dest'] ?? $d['trip_region'];
            $nights = (int) $d['duration_nights'] ?: null;
            $price  = $d['price_millimes'] ?? $d['trip_price'];
            $old    = $d['old_price_millimes'] ?? $d['trip_old'];
            $img    = $d['deal_image'] ?: $d['trip_image'];
        } elseif ($d['supplier_hotel_id']) {
            $tripTo = '/hotels/' . $d['supplier_hotel_id'];
            $target = $tripTo;
            $loc    = $d['hotel_city'];
            $nights = null;
            $price  = $d['price_millimes'];
            $old    = $d['old_price_millimes'];
            $img    = $d['deal_image'] ?: $d['main_image_url'];
        } else {                                              // offre libre : ni voyage ni hôtel
            $tripTo = null;
            $target = $d['link_url'] ?: null;                 // lien facultatif, sinon formulaire de contact
            $loc    = null;
            $nights = null;
            $price  = $d['price_millimes'];
            $old    = $d['old_price_millimes'];
            $img    = $d['deal_image'];
        }
        $p = $price === null ? null : (int) $price / 1000;
        $o = $old === null ? null : (int) $old / 1000;
        $o = $p !== null && $o !== null && $o > $p ? $o : null;
        $pct = $o ? (int) round((1 - $p / $o) * 100) : null;
        if ($img && str_starts_with($img, '/') && !str_starts_with($img, '//')) {
            $img = $base . $img;
        }

        return [
            'id' => (int) $d['id'], 'title' => $tr['title'] ?? $d['title'],
            'description' => $tr['description'] ?? $d['description'],
            'category' => $d['cat_slug'], 'category_name' => $this->tr($d['cat_i18n'], $lang)['name'] ?? $d['cat_name'],
            'location' => $loc, 'nights' => $nights, 'image_url' => $img ?: null,
            'price' => $p, 'old_price' => $o, 'currency' => $d['currency'],
            'discount_percent' => $pct, 'discount_label' => $d['discount_text'] ?: ($pct ? "-$pct%" : null),
            'valid_until' => $d['valid_until'], 'featured' => (bool) $d['is_featured'],
            'to'      => '/bons-plans/' . $d['id'],          // la carte mène à la fiche du bon plan
            'target'  => $target,                            // bouton « Réserver cette offre »
            'trip_to' => $tripTo,                            // « Voir le programme complet » (null = offre libre)
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

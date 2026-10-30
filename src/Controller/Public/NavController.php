<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Données du menu du site : destinations, régions de circuits, catégories de bons plans (actives seulement). */
final class NavController extends AbstractController
{
    private const LANGS = ['en', 'ar'];

    public function __construct(private Connection $db) {}

    #[Route('/api/public/nav', methods: ['GET'])]
    public function __invoke(Request $r): JsonResponse
    {
        $lang = $r->query->getString('lang');
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'fr';

        $res = $this->json([
            // Omra = destination spéciale (kind 'omra') : elle a sa propre page, pas de sous-menu
            'destinations'    => $this->rows("SELECT slug, name, i18n FROM destinations WHERE is_active = 1 AND kind = 'abroad' ORDER BY sort_order, name", $lang),
            'regions'         => $this->rows('SELECT slug, name, i18n FROM circuit_regions WHERE is_active = 1 ORDER BY sort_order, name', $lang),
            'deal_categories' => $this->rows('SELECT slug, name, i18n FROM deal_categories WHERE is_active = 1 ORDER BY sort_order, name', $lang),
        ]);
        $res->setPublic()->setMaxAge(300);      // le menu change rarement : 5 min de cache navigateur / CDN

        return $res;
    }

    private function rows(string $sql, string $lang): array
    {
        return array_map(function (array $x) use ($lang) {
            $tr = $lang !== 'fr' ? (json_decode($x['i18n'] ?: '{}', true)[$lang] ?? []) : [];
            return ['slug' => $x['slug'], 'name' => $tr['name'] ?? $x['name']];
        }, $this->db->fetchAllAssociative($sql));
    }
}
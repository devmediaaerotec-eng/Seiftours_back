<?php
namespace App\Controller\Public;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DestinationController extends AbstractController
{
    #[Route('/api/public/destinations', methods: ['GET'])]
    public function list(Request $request, Connection $db): JsonResponse
    {
        $lang = $request->query->getString('lang', 'fr');
        $rows = $db->fetchAllAssociative(
            'SELECT slug, name, kind, country, city, tagline, image_url, i18n
             FROM destinations WHERE is_active = 1 ORDER BY sort_order'
        );

        $out = array_map(function (array $r) use ($lang) {
            $i18n = json_decode($r['i18n'] ?? '{}', true) ?: [];
            unset($r['i18n']);
            return ($lang !== 'fr' && isset($i18n[$lang])) ? array_merge($r, $i18n[$lang]) : $r;
        }, $rows);

        return $this->json($out);
    }
}
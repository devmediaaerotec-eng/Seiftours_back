<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Service\CatalogCrud;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/deals')]
#[IsGranted('perm:deals.manage')]
final class DealAdminController extends AbstractCatalogController
{
    public function __construct(CatalogCrud $crud, private Connection $db)
    {
        parent::__construct($crud);
    }

    protected function config(): array
    {
        return [
            'table'  => 'deals',
            'entity' => 'deal',
            'flags'  => ['is_active', 'is_featured'],
            'fields' => [
                'category_id'        => ['type' => 'fk', 'table' => 'deal_categories', 'required' => true],
                'title'              => ['type' => 'string', 'required' => true, 'max' => 190],
                'description'        => ['type' => 'string', 'max' => 500],
                'hotel_id'           => ['type' => 'fk', 'table' => 'hotels'],
                'trip_id'            => ['type' => 'fk', 'table' => 'trips'],
                'discount_text'      => ['type' => 'string', 'max' => 40],
                'old_price_millimes' => ['type' => 'int', 'min' => 0],
                'price_millimes'     => ['type' => 'int', 'min' => 0],
                'currency'           => ['type' => 'enum', 'values' => ['TND', 'EUR', 'USD'], 'default' => 'TND'],
                'image_url'          => ['type' => 'string', 'max' => 500],
                'valid_from'         => ['type' => 'date'],
                'valid_until'        => ['type' => 'date'],
                'is_featured'        => ['type' => 'bool', 'default' => false],
                'is_active'          => ['type' => 'bool', 'default' => true],
                'sort_order'         => ['type' => 'int', 'min' => -1000, 'max' => 30000, 'default' => 0],
            ],
            'i18n' => ['title', 'description', 'discount_text'],
        ];
    }

    protected function guard(): ?callable
    {
        return static function (array $row): array {
            $e = [];
            $hasTrip = !empty($row['trip_id']);
            $hasHotel = !empty($row['hotel_id']);
            if (!$hasTrip && !$hasHotel) {
                $e['target'] = 'Choisissez un voyage ou un hôtel.';
            } elseif ($hasTrip && $hasHotel) {
                $e['target'] = 'Une offre vise un voyage OU un hôtel, pas les deux.';
            }
            if ($row['old_price_millimes'] !== null && $row['price_millimes'] !== null
                && $row['old_price_millimes'] <= $row['price_millimes']) {
                $e['old_price_millimes'] = "L'ancien prix doit être supérieur au prix actuel.";
            }
            if ($row['valid_from'] && $row['valid_until'] && $row['valid_until'] < $row['valid_from']) {
                $e['valid_until'] = 'La fin doit être après le début.';
            }
            return $e;
        };
    }

    #[Route('/form-options', methods: ['GET'])]
    public function formOptions(): JsonResponse
    {
        return $this->json([
            'categories' => $this->db->fetchAllAssociative(
                'SELECT id, name, is_active FROM deal_categories ORDER BY sort_order, name'),
            'trips' => $this->db->fetchAllAssociative(
                'SELECT id, title, type FROM trips ORDER BY title'),
        ]);
    }

    #[Route('/hotels', methods: ['GET'])]
    public function hotels(Request $r): JsonResponse
    {
        $q = trim($r->query->getString('q'));
        if (mb_strlen($q) < 2) {
            return $this->json([]);
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';

        return $this->json($this->db->fetchAllAssociative(
            'SELECT id, name, city, stars FROM hotels
             WHERE is_visible = 1 AND (name LIKE ? OR city LIKE ?)
             ORDER BY name LIMIT 20',
            [$like, $like]
        ));
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $r): JsonResponse
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Tunis')))->format('Y-m-d');
        $where = ['1=1'];
        $params = [];

        if (($cat = $r->query->getInt('category')) > 0) {
            $where[] = 'd.category_id = ?';
            $params[] = $cat;
        }
        switch ($r->query->getString('status')) {
            case 'active':
                $where[] = 'd.is_active = 1 AND (d.valid_until IS NULL OR d.valid_until >= ?)';
                $params[] = $today;
                break;
            case 'expired':
                $where[] = 'd.valid_until IS NOT NULL AND d.valid_until < ?';
                $params[] = $today;
                break;
            case 'hidden':
                $where[] = 'd.is_active = 0';
                break;
        }
        if (($q = trim($r->query->getString('q'))) !== '') {
            $where[] = 'd.title LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }

        $limit  = max(1, min(100, $r->query->getInt('limit', 20)));
        $page   = max(1, $r->query->getInt('page', 1));
        $offset = ($page - 1) * $limit;
        $cond   = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM deals d WHERE $cond", $params);
        $rows = $this->db->fetchAllAssociative(
            "SELECT d.id, d.title, d.discount_text, d.price_millimes, d.old_price_millimes, d.currency,
                    d.image_url, d.valid_from, d.valid_until, d.is_featured, d.is_active, d.sort_order,
                    c.name AS category_name, t.title AS trip_title, h.name AS hotel_name
             FROM deals d
             JOIN deal_categories c ON c.id = d.category_id
             LEFT JOIN trips t      ON t.id = d.trip_id
             LEFT JOIN hotels h     ON h.id = d.hotel_id
             WHERE $cond
             ORDER BY d.sort_order, d.id DESC
             LIMIT $limit OFFSET $offset",
            $params
        );

        $items = array_map(static function (array $row) use ($today) {
            $row['is_active']   = (bool) $row['is_active'];
            $row['is_featured'] = (bool) $row['is_featured'];
            $row['expired']     = $row['valid_until'] !== null && $row['valid_until'] < $today;
            return $row;
        }, $rows);

        return $this->json(['total' => $total, 'page' => $page, 'limit' => $limit, 'items' => $items]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $row = $this->db->fetchAssociative(
            'SELECT d.*, t.title AS trip_title, h.name AS hotel_name, h.city AS hotel_city, h.stars AS hotel_stars
             FROM deals d
             LEFT JOIN trips t  ON t.id = d.trip_id
             LEFT JOIN hotels h ON h.id = d.hotel_id
             WHERE d.id = ?',
            [$id]
        );

        return $row
            ? $this->json($this->crud->normalize($row))
            : $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/{id}/duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        $d = $this->db->fetchAssociative('SELECT * FROM deals WHERE id = ?', [$id]);
        if (!$d) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        unset($d['id'], $d['created_at'], $d['updated_at']);
        $d['title'] = mb_substr($d['title'], 0, 180) . ' (copie)';
        $d['is_active'] = 0;
        $d['is_featured'] = 0;
        $this->db->insert('deals', $d);
        $newId = (int) $this->db->lastInsertId();
        $this->crud->audit($user, $r->getClientIp(), 'deal.duplicate', 'deal', $newId, ['source_id' => $id], null);

        return $this->json(['id' => $newId], Response::HTTP_CREATED);
    }
}
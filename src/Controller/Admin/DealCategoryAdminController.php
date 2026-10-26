<?php
namespace App\Controller\Admin;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/deal-categories')]
#[IsGranted('perm:deals.manage')]
final class DealCategoryAdminController extends AbstractCatalogController
{
    protected function config(): array
    {
        return [
            'table'  => 'deal_categories',
            'entity' => 'deal_category',
            'fields' => [
                'slug'       => ['type' => 'slug', 'required' => true, 'max' => 60],
                'name'       => ['type' => 'string', 'required' => true, 'max' => 120],
                'sort_order' => ['type' => 'int', 'min' => -1000, 'max' => 30000, 'default' => 0],
                'is_active'  => ['type' => 'bool', 'default' => true],
            ],
            'i18n'  => ['name'],
            'usage' => [['deals', 'category_id']],
        ];
    }
}
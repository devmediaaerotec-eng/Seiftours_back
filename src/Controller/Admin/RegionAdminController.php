<?php
namespace App\Controller\Admin;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/regions')]
#[IsGranted('perm:destinations.manage')]
final class RegionAdminController extends AbstractCatalogController
{
    protected function config(): array
    {
        return [
            'table'  => 'circuit_regions',
            'entity' => 'region',
            'fields' => [
                'slug'        => ['type' => 'slug', 'required' => true, 'max' => 80],
                'name'        => ['type' => 'string', 'required' => true, 'max' => 120],
                'description' => ['type' => 'string', 'max' => 500],
                'image_url'   => ['type' => 'string', 'max' => 500],
                'sort_order'  => ['type' => 'int', 'min' => -1000, 'max' => 30000, 'default' => 0],
                'is_active'   => ['type' => 'bool', 'default' => true],
            ],
            'i18n'  => ['name', 'description'],
            'usage' => [['trips', 'region_id']],
        ];
    }
}
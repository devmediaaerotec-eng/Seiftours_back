<?php
namespace App\Controller\Admin;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/destinations')]
#[IsGranted('perm:destinations.manage')]
final class DestinationAdminController extends AbstractCatalogController
{
    protected function config(): array
    {
        return [
            'table'  => 'destinations',
            'entity' => 'destination',
            'fields' => [
                'slug'        => ['type' => 'slug', 'required' => true, 'max' => 80],
                'name'        => ['type' => 'string', 'required' => true, 'max' => 120],
                'kind'        => ['type' => 'enum', 'values' => ['omra', 'abroad'], 'required' => true],
                'country'     => ['type' => 'string', 'max' => 80],
                'city'        => ['type' => 'string', 'max' => 80],
                'tagline'     => ['type' => 'string', 'max' => 190],
                'description' => ['type' => 'text', 'max' => 20000],
                'image_url'   => ['type' => 'string', 'max' => 500],
                'sort_order'  => ['type' => 'int', 'min' => -1000, 'max' => 30000, 'default' => 0],
                'is_active'   => ['type' => 'bool', 'default' => true],
            ],
            'i18n'  => ['name', 'tagline', 'description'],
            'usage' => [['trips', 'destination_id']],
        ];
    }

    protected function guard(): ?callable
    {
        // Une Omra doit viser une destination "omra" : on ne change pas le type d'une destination déjà utilisée
        return function (array $row, ?array $existing): array {
            if ($existing && $existing['kind'] !== $row['kind']
                && $this->crud->count('trips', 'destination_id', (int) $existing['id']) > 0) {
                return ['kind' => 'Impossible : des voyages utilisent déjà cette destination.'];
            }
            return [];
        };
    }
}
<?php
namespace App\Controller\Admin;

use App\Entity\StaffUser;
use App\Security\PermissionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class UploadController extends AbstractController
{
    /** dossier => permission requise */
    private const FOLDERS = [
    'trips'        => 'trips.manage',
    'deals'        => 'deals.manage',
    'destinations' => 'destinations.manage',
    'buses'        => 'fleet.manage',
    'drivers'      => 'fleet.manage',
];
    private const MIMES     = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_SIDE  = 2000;

    public function __construct(
        private PermissionChecker $perms,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    #[Route('/api/admin/uploads', methods: ['POST'])]
    public function __invoke(Request $r, #[CurrentUser] StaffUser $user): JsonResponse
    {
        if (!function_exists('imagecreatefromstring')) {
            return $this->fail('gd_missing', 'Extension PHP GD manquante sur le serveur.', 500);
        }

        $folder = $r->request->getString('folder', 'trips');
        if (!isset(self::FOLDERS[$folder])) {
            return $this->fail('invalid_folder', 'Dossier inconnu.', Response::HTTP_BAD_REQUEST);
        }
        if (!$this->perms->has($user, self::FOLDERS[$folder])) {
            return $this->fail('forbidden', "Vous n'avez pas le droit d'envoyer ce type de fichier.", Response::HTTP_FORBIDDEN);
        }

        $file = $r->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->fail('no_file', 'Aucun fichier reçu (fichier trop volumineux ?).', Response::HTTP_BAD_REQUEST);
        }
        if (!$file->isValid()) {
            return $this->fail('upload_error', $file->getErrorMessage(), Response::HTTP_BAD_REQUEST);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return $this->fail('too_large', 'Image trop lourde (8 Mo maximum).', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        if (!in_array($file->getMimeType(), self::MIMES, true)) {
            return $this->fail('unsupported', 'Format non accepté (JPEG, PNG ou WebP).', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $path = $file->getPathname();
        $info = @getimagesize($path);
        if ($info === false) {
            return $this->fail('invalid_image', 'Fichier image invalide.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        if ($info[0] * $info[1] > 40_000_000) {       // évite de saturer la mémoire
            return $this->fail('too_many_pixels', "Dimensions de l'image trop grandes.", Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $src = @imagecreatefromstring((string) file_get_contents($path));
        if ($src === false) {
            return $this->fail('invalid_image', "Impossible de lire l'image.", Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        imagepalettetotruecolor($src);

        // Redresse les photos de téléphone (orientation EXIF)
        if ($file->getMimeType() === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = @exif_read_data($path)['Orientation'] ?? 1;
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle !== 0 && ($rotated = imagerotate($src, $angle, 0)) !== false) {
                $src = $rotated;
            }
        }

        // Réduit à 2000 px maximum
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        if ($scale < 1) {
            $scaled = imagescale($src, (int) round($w * $scale), (int) round($h * $scale));
            if ($scaled !== false) {
                $src = $scaled;
                $w = imagesx($src);
                $h = imagesy($src);
            }
        }

        $sub = date('Y/m');
        $dir = $this->projectDir . "/public/uploads/$folder/$sub";
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->fail('storage', "Impossible de créer le dossier d'envoi.", 500);
        }

        $webp = function_exists('imagewebp');
        $name = bin2hex(random_bytes(10)) . ($webp ? '.webp' : '.jpg');
        $dest = "$dir/$name";

        if ($webp) {
            imagesavealpha($src, true);
            $ok = imagewebp($src, $dest, 82);
        } else {
            $bg = imagecreatetruecolor($w, $h);
            imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));   // fond blanc à la place de la transparence
            imagecopy($bg, $src, 0, 0, 0, 0, $w, $h);
            $ok = imagejpeg($bg, $dest, 85);
        }
        if (!$ok) {
            return $this->fail('storage', "Échec de l'enregistrement de l'image.", 500);
        }

        return $this->json([
            'url'    => "/uploads/$folder/$sub/$name",
            'width'  => $w,
            'height' => $h,
            'size'   => filesize($dest),
        ], Response::HTTP_CREATED);
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $code, 'message' => $message], $status);
    }
}
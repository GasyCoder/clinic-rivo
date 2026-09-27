<?php

namespace App\Services\Administration;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-194 — la photo d'identité 4 × 4 d'un dossier RH.
 *
 * L'écran la recadre déjà en carré, mais le serveur ne s'y fie pas : il
 * relit l'image, la recoupe au carré central, la ramène à 600 px et la
 * réencode en JPEG. Réencoder retire aussi les métadonnées (position GPS,
 * appareil) qu'une photo prise au téléphone transporte.
 *
 * Le fichier vit sur le disque privé, jamais sous public/ : il se lit par
 * un contrôleur qui revérifie le droit, comme une pièce RH (ADR-066).
 */
class EmployeePhotoStore
{
    public const SIZE = 600;

    public function store(UploadedFile $file): string
    {
        $path = 'hr/employee-photos/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $this->normalize($file));

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function normalize(UploadedFile $file): string
    {
        $contents = (string) file_get_contents($file->getRealPath());

        if (! function_exists('imagecreatefromstring')) {
            return $contents;
        }

        $source = @imagecreatefromstring($contents);

        if (! $source) {
            throw ValidationException::withMessages([
                'photo' => 'Cette image ne peut pas être lue. Utilisez une photo JPEG, PNG ou WebP.',
            ]);
        }

        $source = $this->orient($source, $file);
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $size = min(self::SIZE, $side);
        $target = imagecreatetruecolor($size, $size);
        // Un PNG transparent ne devient pas noir : la photo d'identité a un fond clair.
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled(
            $target, $source, 0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            $size, $size, $side, $side,
        );

        ob_start();
        imagejpeg($target, null, 88);

        return (string) ob_get_clean();
    }

    /** Une photo de téléphone est souvent enregistrée couchée, avec l'angle dans ses métadonnées. */
    private function orient(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || $file->getMimeType() !== 'image/jpeg') {
            return $image;
        }

        $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => false,
        };

        return $rotated ?: $image;
    }
}

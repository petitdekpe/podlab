<?php

namespace App\Site;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Image envoyée depuis /admin : contrôlée, réduite et convertie en JPEG.
 */
final class ImageEnvoyee
{
    private const MAX_PIXELS = 40_000_000;

    /**
     * @param (\Closure(int, int): ?string)|null $format refus selon la largeur et la hauteur
     *
     * @return array{0: string|null, 1: string|null} [JPEG, message d'erreur]
     */
    public static function jpeg(UploadedFile $envoi, int $minWidth, int $maxWidth, ?\Closure $format = null): array
    {
        if (!$envoi->isValid()) {
            return [null, \in_array($envoi->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? 'Image trop lourde pour le serveur. Réduisez-la puis réessayez.'
                : 'L\'image n\'est pas arrivée en entier. Réessayez.'];
        }

        // Le type est lu dans l'image elle-même, jamais d'après son nom
        $taille = @getimagesize($envoi->getPathname());
        $type = false !== $taille ? $taille[2] : 0;
        if (!\in_array($type, [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP], true)) {
            return [null, 'Ce fichier n\'est pas une image JPEG, PNG ou WebP.'];
        }
        [$largeur, $hauteur] = $taille;
        if ($largeur < $minWidth) {
            return [null, sprintf('Image trop petite : %d pixels de large, il en faut au moins %d.', $largeur, $minWidth)];
        }
        if (null !== $format && null !== $refus = $format($largeur, $hauteur)) {
            return [null, $refus];
        }
        if ($largeur * $hauteur > self::MAX_PIXELS) {
            return [null, sprintf('Image trop grande. Réduisez-la (%d pixels de large suffisent) puis réessayez.', $maxWidth)];
        }

        $jpeg = self::convertir($envoi->getPathname(), $type, $largeur, $hauteur, $maxWidth);

        return null === $jpeg ? [null, 'L\'image n\'a pas pu être lue. Enregistrez-la en JPEG puis réessayez.'] : [$jpeg, null];
    }

    /** Écrit à côté puis renomme : le site ne sert jamais un fichier à moitié écrit. */
    public static function ecrire(string $fichier, string $jpeg): bool
    {
        $dossier = \dirname($fichier);
        $temporaire = $fichier.'.'.bin2hex(random_bytes(4));
        if ((!is_dir($dossier) && !@mkdir($dossier, 0o775, true) && !is_dir($dossier))
            || false === @file_put_contents($temporaire, $jpeg)
            || !@rename($temporaire, $fichier)) {
            @unlink($temporaire);

            return false;
        }

        return true;
    }

    private static function convertir(string $source, int $type, int $largeur, int $hauteur, int $maxWidth): ?string
    {
        if (!\function_exists('imagecreatetruecolor')) {
            // Sans l'extension GD, seul le JPEG (déjà réduit par le navigateur) est accepté
            return \IMAGETYPE_JPEG === $type ? (file_get_contents($source) ?: null) : null;
        }

        @ini_set('memory_limit', '512M');
        $image = match ($type) {
            \IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            \IMAGETYPE_PNG => @imagecreatefrompng($source),
            default => \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        };
        if (false === $image) {
            return null;
        }

        $l = min($largeur, $maxWidth);
        $h = max(1, (int) round($hauteur * $l / $largeur));
        $sortie = imagecreatetruecolor($l, $h); // fond noir sous une éventuelle transparence
        imagecopyresampled($sortie, $image, 0, 0, 0, 0, $l, $h, $largeur, $hauteur);
        imageinterlace($sortie, true);

        ob_start();
        $ok = imagejpeg($sortie, null, 85);
        $jpeg = (string) ob_get_clean();

        return $ok && '' !== $jpeg ? $jpeg : null;
    }
}

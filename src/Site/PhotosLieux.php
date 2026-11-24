<?php

namespace App\Site;

use App\Entity\Lieu;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photos des lieux envoyées depuis /admin. Les fichiers vivent hors du dépôt
 * (var/lieux) : un déploiement ne les écrase pas. Chaque envoi a un nouveau nom,
 * donc une nouvelle adresse : le navigateur peut garder la photo sans limite.
 */
final class PhotosLieux
{
    public const MAX_WIDTH = 1600;
    public const MOTIF = '[a-f0-9]{24}\.jpg';
    private const MIN_WIDTH = 600;

    public function __construct(private readonly string $dossier)
    {
    }

    /** @return array{0: string|null, 1: string|null} [nom du fichier, message d'erreur] */
    public function enregistrer(UploadedFile $envoi): array
    {
        [$jpeg, $erreur] = ImageEnvoyee::jpeg($envoi, self::MIN_WIDTH, self::MAX_WIDTH, static fn (int $l, int $h): ?string => $h > 2 * $l || $l > 3 * $h
            ? 'La photo est trop étirée. Choisissez-en une au format paysage (idéalement 16:9, par exemple 1600 × 900).'
            : null);
        if (null === $jpeg) {
            return [null, $erreur];
        }

        $nom = bin2hex(random_bytes(12)).'.jpg';

        return ImageEnvoyee::ecrire($this->dossier.'/'.$nom, $jpeg)
            ? [$nom, null]
            : [null, 'Le serveur n\'a pas pu enregistrer la photo (droits d\'écriture sur le dossier var/).'];
    }

    /** Chemin de la photo envoyée, ou null. */
    public function fichier(string $nom): ?string
    {
        if (!preg_match('/^'.self::MOTIF.'$/D', $nom)) {
            return null;
        }
        $fichier = $this->dossier.'/'.$nom;

        return is_file($fichier) ? $fichier : null;
    }

    /** Supprime une photo envoyée (celles livrées avec le site ne sont pas touchées). */
    public function supprimer(string $nom): void
    {
        if (null !== $fichier = $this->fichier($nom)) {
            @unlink($fichier);
        }
    }

    public function disponible(Lieu $lieu): bool
    {
        return $lieu->photoDuSite() || null !== $this->fichier($lieu->photo);
    }
}

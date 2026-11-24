<?php

namespace App\Site;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Affiche du premier écran : celle envoyée depuis /admin si elle existe, sinon
 * l'image d'origine du site. Le fichier vit hors du dépôt (var/affiche) : un
 * déploiement ne l'écrase pas, et il ne dépend pas de la base de données.
 */
final class Affiche
{
    public const ORIGINE = 'images/affiche-moi-a-quatre-pattes.jpg';
    public const MAX_WIDTH = 1200;
    private const MIN_WIDTH = 600;

    public function __construct(private readonly string $dossier)
    {
    }

    /** Fichier de l'affiche envoyée depuis /admin, ou null. */
    public function fichier(): ?string
    {
        $fichier = $this->chemin();

        return is_file($fichier) ? $fichier : null;
    }

    /** @return array{version: string, width: int, height: int}|null null : affiche d'origine */
    public function personnalisee(): ?array
    {
        $fichier = $this->fichier();
        $taille = null !== $fichier ? @getimagesize($fichier) : false;
        if (false === $taille) {
            return null;
        }

        return ['version' => substr(hash_file('xxh128', $fichier), 0, 12), 'width' => $taille[0], 'height' => $taille[1]];
    }

    /** Remplace l'affiche. Retourne le message d'erreur, ou null si c'est fait. */
    public function remplacer(UploadedFile $envoi): ?string
    {
        [$jpeg, $erreur] = ImageEnvoyee::jpeg($envoi, self::MIN_WIDTH, self::MAX_WIDTH, static fn (int $l, int $h): ?string => $l > $h || $h > 2 * $l
            ? 'L\'affiche doit être en portrait ou carrée (idéalement au format 4:5, par exemple 1200 × 1500).'
            : null);
        if (null === $jpeg) {
            return $erreur;
        }

        return ImageEnvoyee::ecrire($this->chemin(), $jpeg) ? null : 'Le serveur n\'a pas pu enregistrer l\'image (droits d\'écriture sur le dossier var/).';
    }

    /** Revient à l'affiche d'origine du site. */
    public function retablir(): bool
    {
        return null === $this->fichier() || @unlink($this->chemin());
    }

    private function chemin(): string
    {
        return $this->dossier.'/affiche.jpg';
    }
}

<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un lieu (salle) où se tiennent des évènements, géré dans /admin.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lieu')]
#[ORM\UniqueConstraint(name: 'uniq_lieu_nom', columns: ['nom'])]
class Lieu
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $nom = '';

    #[ORM\Column(length: 160)]
    public string $detail = '';

    #[ORM\Column(length: 200)]
    public string $adresse = '';

    /** Recherche Google Maps ; vide : nom et adresse */
    #[ORM\Column(length: 255)]
    public string $maps = '';

    /** Toutes les places, pour le taux de remplissage de /ventes */
    #[ORM\Column(nullable: true)]
    public ?int $capacite = null;

    /** Photo envoyée depuis /admin (nom de fichier, voir PhotosLieux), livrée avec le site (« images/… »), ou '' */
    #[ORM\Column(length: 120)]
    public string $photo = '';

    /** Description de la photo, pour les lecteurs d'écran */
    #[ORM\Column(length: 200)]
    public string $photoAlt = '';

    /** Partie de la photo gardée au recadrage, en pourcentage */
    #[ORM\Column]
    public int $cadrageX = 50;

    #[ORM\Column]
    public int $cadrageY = 50;

    public function __construct(string $nom = '')
    {
        $this->nom = $nom;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Copie sans lien avec Doctrine. */
    public function copie(): self
    {
        $copie = new self();
        foreach (['nom', 'detail', 'adresse', 'maps', 'capacite', 'photo', 'photoAlt', 'cadrageX', 'cadrageY'] as $champ) {
            $copie->$champ = $this->$champ;
        }

        return $copie;
    }

    /** Photo livrée avec le site (assets/images), par opposition à une photo envoyée. */
    public function photoDuSite(): bool
    {
        return str_starts_with($this->photo, 'images/');
    }

    public function recherche(): string
    {
        return '' !== $this->maps ? $this->maps : trim($this->nom.', '.$this->adresse, ', ');
    }
}

<?php

namespace App\Entity;

use App\Live\LiveSettings;
use App\Site\DateFr;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un évènement du site (une séance, à une date, dans une salle), géré dans /admin.
 * Prix et places restantes viennent de Tick Africa (voir Billetterie).
 */
#[ORM\Entity]
#[ORM\Table(name: 'evenement')]
#[ORM\UniqueConstraint(name: 'uniq_evenement_cle', columns: ['cle'])]
class Evenement
{
    public const TITRE = 'Moi, à quatre pattes ?!';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant public, fixé à la création : liens ?seance=…, commandes Tick Africa, statistiques */
    #[ORM\Column(length: 64)]
    private string $cle;

    #[ORM\Column(length: 120)]
    public string $titre = self::TITRE;

    /** Heure du Bénin, sans fuseau : lue par debut() */
    #[ORM\Column]
    private \DateTimeImmutable $debut;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $fin = null;

    /** « 12h30 » : accueil du public, ou '' */
    #[ORM\Column(length: 5)]
    public string $portes = '';

    /** « 12h55 » : plus aucune entrée après, ou '' */
    #[ORM\Column(length: 5)]
    public string $fermeture = '';

    #[ORM\ManyToOne(fetch: 'EAGER')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Lieu $lieu = null;

    #[ORM\Column(length: 36)]
    public string $tickafricaEventId = '';

    /** Identifiants des billets à vendre, séparés par des virgules ; vide : tous */
    #[ORM\Column(type: Types::TEXT)]
    public string $tickafricaTarifs = '';

    /** Places dans la salle pour cet évènement ; null : capacité du lieu */
    #[ORM\Column(nullable: true)]
    public ?int $capacite = null;

    /** Affiché « Sold out » quoi que dise Tick Africa */
    #[ORM\Column]
    public bool $complet = false;

    #[ORM\Column]
    public bool $publie = true;

    /** Compté dans le bilan /ventes */
    #[ORM\Column]
    public bool $bilan = true;

    public function __construct(string $cle, \DateTimeImmutable $debut)
    {
        $this->cle = $cle;
        $this->setDebut($debut);
    }

    /** Copie non enregistrée, dans le même lieu. */
    public function copie(?string $cle = null): self
    {
        $copie = new self($cle ?? $this->cle, $this->debut());
        $copie->setFin($this->fin());
        foreach (['titre', 'portes', 'fermeture', 'lieu', 'tickafricaEventId', 'tickafricaTarifs', 'capacite', 'complet', 'publie', 'bilan'] as $champ) {
            $copie->$champ = $this->$champ;
        }

        return $copie;
    }

    /** Copie (lieu compris) sans lien avec Doctrine, à mettre en cache. */
    public function releve(): self
    {
        $copie = $this->copie();
        $copie->lieu = $this->lieu?->copie();

        return $copie;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    /** Uniquement avant le premier enregistrement. */
    public function setCle(string $cle): void
    {
        if (null !== $this->id) {
            throw new \LogicException('La clé d\'un évènement enregistré ne change plus.');
        }
        $this->cle = $cle;
    }

    public function debut(): \DateTimeImmutable
    {
        return DateFr::relire($this->debut);
    }

    public function setDebut(\DateTimeImmutable $debut): void
    {
        $this->debut = DateFr::local($debut);
    }

    public function fin(): ?\DateTimeImmutable
    {
        return null === $this->fin ? null : DateFr::relire($this->fin);
    }

    public function setFin(?\DateTimeImmutable $fin): void
    {
        $this->fin = null === $fin ? null : DateFr::local($fin);
    }

    /** « Dim. 29 nov. 2026 · 13h00 · Majestic Marina » */
    public function libelle(): string
    {
        $debut = $this->debut();

        return DateFr::jourCourt($debut).' '.$debut->format('Y').' · '.DateFr::heure($debut).' · '.$this->lieu?->nom;
    }

    /** « 13h00 – 15h00 », ou « 13h00 » sans heure de fin */
    public function horaire(): string
    {
        $fin = $this->fin();

        return DateFr::heure($this->debut()).(null === $fin ? '' : ' – '.DateFr::heure($fin));
    }

    /** @return array{name: string, sub: string, address: string, maps: string} */
    public function salle(): array
    {
        return [
            'name' => $this->lieu?->nom ?? '',
            'sub' => $this->lieu?->detail ?? '',
            'address' => $this->lieu?->adresse ?? '',
            'maps' => $this->lieu?->recherche() ?? '',
        ];
    }

    /** Places dans la salle, pour le taux de remplissage de /ventes ; null si inconnu. */
    public function places(): ?int
    {
        return $this->capacite ?? $this->lieu?->capacite;
    }

    /** @return list<string> identifiants des billets à vendre ; vide : tous */
    public function tarifs(): array
    {
        return array_values(array_filter(array_map(LiveSettings::normalizeUuid(...), explode(',', $this->tickafricaTarifs))));
    }

    /** Relie à Tick Africa avec « uuid » ou « uuid:uuid-tarif,uuid-tarif ». */
    public function relier(string $lien): void
    {
        [$event, $this->tickafricaTarifs] = array_pad(explode(':', trim($lien), 2), 2, '');
        $this->tickafricaEventId = LiveSettings::normalizeUuid($event);
        $this->tickafricaTarifs = implode(',', $this->tarifs());
    }
}

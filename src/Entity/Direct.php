<?php

namespace App\Entity;

use App\Live\LiveSettings;
use App\Site\DateFr;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un direct en ligne (page /live), géré dans /admin.
 */
#[ORM\Entity]
#[ORM\Table(name: 'live_direct')]
class Direct
{
    public const AVANT = 'avant';
    public const EN_COURS = 'en_cours';
    public const TERMINE = 'termine';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $titre = LiveSettings::DEFAULT_TITLE;

    /** Identifiant de la vidéo, ou '' : jamais dans le HTML public */
    #[ORM\Column(length: 11)]
    public string $youtube = '';

    /** Heure du Bénin, sans fuseau : lue par ouverture() */
    #[ORM\Column]
    private \DateTimeImmutable $ouverture;

    #[ORM\Column]
    private \DateTimeImmutable $fin;

    /** Séance diffusée, s'il y en a une */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Evenement $evenement = null;

    /** Évènement Tick Africa dont les billets ouvrent ce direct, ou '' : aucun nouveau billet accepté */
    #[ORM\Column(length: 36)]
    public string $tickafricaEventId = '';

    /** Formulaire d'achat Tick Africa affiché dans la fenêtre de l'accueil */
    #[ORM\Column(length: 500)]
    public string $billetterieUrl = '';

    /** Interrupteur : à faux, aucun billet ne donne accès (le supercode reste valable) */
    #[ORM\Column]
    public bool $accesOuvert = true;

    #[ORM\Column]
    public bool $filigrane = true;

    #[ORM\Column]
    public bool $publie = true;

    public function __construct(\DateTimeImmutable $ouverture, \DateTimeImmutable $fin)
    {
        $this->setFenetre($ouverture, $fin);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function ouverture(): \DateTimeImmutable
    {
        return DateFr::relire($this->ouverture);
    }

    public function fin(): \DateTimeImmutable
    {
        return DateFr::relire($this->fin);
    }

    public function setFenetre(\DateTimeImmutable $ouverture, \DateTimeImmutable $fin): void
    {
        $this->ouverture = DateFr::local($ouverture);
        $this->fin = DateFr::local($fin);
    }

    public function phase(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();

        return match (true) {
            $now >= $this->fin() => self::TERMINE,
            $now >= $this->ouverture() => self::EN_COURS,
            default => self::AVANT,
        };
    }

    public function chevauche(self $autre): bool
    {
        return $this->ouverture() < $autre->fin() && $autre->ouverture() < $this->fin();
    }

    /** Début annoncé au public : celui de la séance, sinon l'ouverture du direct. */
    public function debutAffiche(): \DateTimeImmutable
    {
        return $this->evenement?->debut() ?? $this->ouverture();
    }

    /** « 13h00 – 15h00 » : horaire de la séance, sinon fenêtre du direct */
    public function horaire(): string
    {
        return $this->evenement?->horaire() ?? DateFr::heure($this->ouverture()).' – '.DateFr::heure($this->fin());
    }

    /** « Dimanche 22 novembre » */
    public function jour(): string
    {
        return DateFr::jour($this->debutAffiche());
    }

    /** « 22 novembre » */
    public function date(): string
    {
        return DateFr::date($this->debutAffiche());
    }

    /** Lien d'achat utilisable dans la page, ou ''. */
    public function billetterie(): string
    {
        return LiveSettings::normalizeEmbedUrl($this->billetterieUrl);
    }

    /** Nom de la fenêtre d'achat sur l'accueil */
    public function fenetre(): string
    {
        return 'live-tickets-'.$this->id;
    }
}

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

    /** Habillage publicitaire en L : nombre de passages par direct, et durée par défaut en secondes */
    public const PASSAGES = 3;
    public const HABILLAGE_DUREE = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $titre = LiveSettings::DEFAULT_TITLE;

    /** Identifiant de la vidéo, ou '' : jamais dans le HTML public */
    #[ORM\Column(length: 11)]
    public string $youtube = '';

    /** Vidéo déjà enregistrée (et non un direct YouTube), diffusée comme un direct à partir de lancement() */
    #[ORM\Column(options: ['default' => false])]
    public bool $enregistree = false;

    /** « 20:00 » (heure du Bénin), ou '' : début de la séance, à défaut ouverture */
    #[ORM\Column(length: 5, options: ['default' => ''])]
    public string $lancementHeure = '';

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

    #[ORM\Column(options: ['default' => true])]
    public bool $habillage = true;

    /** Durée d'un passage, en secondes */
    #[ORM\Column(options: ['default' => self::HABILLAGE_DUREE])]
    public int $habillageDuree = self::HABILLAGE_DUREE;

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

    /** Vidéo enregistrée : instant où elle démarre pour tout le monde ; null pour un direct YouTube. */
    public function lancement(): ?\DateTimeImmutable
    {
        if (!$this->enregistree) {
            return null;
        }
        if ('' !== $this->lancementHeure) {
            return $this->aLHeure($this->lancementHeure);
        }
        $seance = $this->evenement?->debut();

        return null !== $seance && $seance >= $this->ouverture() && $seance < $this->fin() ? $seance : $this->ouverture();
    }

    /** Instant à partir duquel les billets voient la vidéo. */
    public function debutDiffusion(): \DateTimeImmutable
    {
        return $this->lancement() ?? $this->ouverture();
    }

    public function chevauche(self $autre): bool
    {
        return $this->ouverture() < $autre->fin() && $autre->ouverture() < $this->fin();
    }

    /**
     * Début de chaque passage de l'habillage pour un direct YouTube : à 25, 50 et 75 %
     * de la séance (à défaut, de la fenêtre du direct). Pour une vidéo enregistrée, la
     * liste est vide : seul le lecteur connaît sa durée, il calcule les passages.
     *
     * @return list<\DateTimeImmutable>
     */
    public function passagesHabillage(): array
    {
        if (!$this->habillage || $this->enregistree) {
            return [];
        }

        // La fenêtre ouvre avant le spectacle et ferme après : on vise la séance elle-même
        $debut = max($this->ouverture(), $this->evenement?->debut() ?? $this->ouverture());
        $fin = min($this->fin(), $this->evenement?->fin() ?? $this->fin());
        if ($debut >= $fin) {
            [$debut, $fin] = [$this->ouverture(), $this->fin()];
        }
        $ecart = ($fin->getTimestamp() - $debut->getTimestamp()) / (self::PASSAGES + 1);

        return array_map(
            fn (int $n): \DateTimeImmutable => $debut->modify(sprintf('+%d seconds', (int) ($n * $ecart))),
            range(1, self::PASSAGES),
        );
    }

    /** Première fois que cette heure (« 13:20 ») se présente à partir de l'ouverture : un direct peut passer minuit. */
    public function aLHeure(string $heure): \DateTimeImmutable
    {
        [$h, $m] = array_map(intval(...), explode(':', $heure));
        $moment = $this->ouverture()->setTime($h, $m);

        return $moment < $this->ouverture() ? $moment->modify('+1 day') : $moment;
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

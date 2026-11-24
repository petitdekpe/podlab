<?php

namespace App\Billetterie;

use App\Entity\Direct;
use App\Entity\Evenement;
use App\Site\DateFr;

/**
 * Une séance telle qu'affichée sur le site : ce qui est fixé par nous (date,
 * salle) et ce que Tick Africa nous dit en temps réel (tarifs, places restantes).
 */
final class Seance
{
    public const EN_VENTE = 'en_vente';
    public const COMPLET = 'complet';
    public const PASSEE = 'passee';
    /** Pas (encore) ouverte à la vente chez Tick Africa, ou pas vendable par l'API */
    public const FERMEE = 'fermee';
    /** Tick Africa injoignable, ou séance pas encore reliée à un événement */
    public const INDISPONIBLE = 'indisponible';

    public readonly string $key;
    public readonly string $titre;
    public readonly \DateTimeImmutable $startsAt;
    /** @var array{name: string, sub: string, address: string, maps: string} */
    public readonly array $salle;
    public readonly string $eventId;

    /**
     * @param list<Tarif> $tarifs
     */
    public function __construct(
        public readonly Evenement $evenement,
        public readonly string $status,
        public readonly array $tarifs = [],
        /** Direct en ligne de cette séance, à venir ou en cours */
        public readonly ?Direct $direct = null,
        /** Données affichées issues du dernier relevé réussi (Tick Africa injoignable depuis) */
        public readonly bool $stale = false,
        /** Pourquoi la séance n'est pas en vente (voir REASONS), pour l'administration */
        public readonly string $reason = '',
        /** Nom de l'événement chez Tick Africa, pour vérifier qu'on a relié le bon */
        public readonly string $eventName = '',
    ) {
        $this->key = $evenement->getCle();
        $this->titre = $evenement->titre;
        $this->startsAt = $evenement->debut();
        $this->salle = $evenement->salle();
        $this->eventId = $evenement->tickafricaEventId;
    }

    /** Nom de $status, pour l'administration. */
    public const ETATS = [
        self::EN_VENTE => 'En vente',
        self::COMPLET => 'Complet',
        self::PASSEE => 'Terminé',
        self::FERMEE => 'Vente fermée',
        self::INDISPONIBLE => 'Non relié ou injoignable',
    ];

    /** Explication de $reason, pour l'administration. */
    public const REASONS = [
        'complet_force' => 'Marquée « Sold out » dans l\'administration.',
        'passee' => 'La séance a eu lieu.',
        'non_reliee' => 'Aucun événement Tick Africa renseigné.',
        'sans_cle' => 'Aucune clé API Tick Africa.',
        'injoignable' => 'Tick Africa ne répond pas, ou la clé n\'a pas la portée events:read.',
        'introuvable' => 'Tick Africa ne trouve pas cet événement avec cette clé : identifiant erroné, événement non publié, ou appartenant à un autre compte.',
        'pas_en_vente' => 'Tick Africa indique que l\'événement n\'est pas ouvert à la vente (is_on_sale = false) : vérifiez les dates de vente et la publication dans le back-office.',
        'plan_de_salle' => 'Événement avec plan de salle : Tick Africa ne permet pas de le vendre par l\'API (sellable_via_api = false). Retirez le plan de salle (placement libre) dans le back-office Tick Africa.',
        'aucun_billet' => 'L\'événement n\'a aucun billet chez Tick Africa.',
        'billets_non_trouves' => 'Aucun des billets indiqués dans « Billets à vendre » n\'appartient à cet événement.',
        'epuise' => 'Tous les billets sont vendus ou retirés de la vente.',
    ];

    /** « Dimanche 29 novembre » (heure du Bénin) */
    public function jour(): string
    {
        return DateFr::jour($this->startsAt);
    }

    /** « Dim. 29 nov. » */
    public function jourCourt(): string
    {
        return DateFr::jourCourt($this->startsAt);
    }

    /** « Dim. » */
    public function semaineCourte(): string
    {
        return DateFr::semaineCourte($this->startsAt);
    }

    /** « nov. » */
    public function moisCourt(): string
    {
        return DateFr::moisCourt($this->startsAt);
    }

    /** « 13h00 » */
    public function heure(): string
    {
        return DateFr::heure($this->startsAt);
    }

    public function aVenir(): bool
    {
        return $this->startsAt > new \DateTimeImmutable();
    }

    public function onSale(): bool
    {
        return self::EN_VENTE === $this->status;
    }

    public function soldOut(): bool
    {
        return self::COMPLET === $this->status;
    }

    public function tarif(string $id): ?Tarif
    {
        foreach ($this->tarifs as $tarif) {
            if ($tarif->id === $id) {
                return $tarif;
            }
        }

        return null;
    }

    /** @return list<Tarif> */
    public function tarifsEnVente(): array
    {
        return array_values(array_filter($this->tarifs, static fn (Tarif $t): bool => $t->available));
    }

    /**
     * Le billet de cette séance, au format « item » de Google Analytics.
     *
     * @return array<string, string|int>
     */
    public function article(Tarif $tarif): array
    {
        return [
            'item_id' => $this->key,
            'item_name' => $this->salle['name'].' · '.$this->jourCourt(),
            'item_variant' => $tarif->name,
            'price' => $tarif->price,
        ];
    }

    /** Prix le plus bas parmi les billets en vente (ou tous les billets), null si inconnu. */
    public function prixMin(): ?int
    {
        $prices = array_map(static fn (Tarif $t): int => $t->price, $this->tarifsEnVente() ?: $this->tarifs);

        return [] === $prices ? null : min($prices);
    }

    /** Places restantes, tous tarifs en vente confondus ; null si illimité ou inconnu. */
    public function placesRestantes(): ?int
    {
        $total = 0;
        foreach ($this->tarifsEnVente() as $tarif) {
            if (null === $tarif->remaining) {
                return null;
            }
            $total += $tarif->remaining;
        }

        return $this->onSale() ? $total : null;
    }
}

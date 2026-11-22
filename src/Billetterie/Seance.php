<?php

namespace App\Billetterie;

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

    /**
     * @param array{name: string, sub: string, address: string, maps: string} $salle
     * @param list<Tarif>                                                       $tarifs
     */
    public function __construct(
        public readonly string $key,
        public readonly \DateTimeImmutable $startsAt,
        public readonly array $salle,
        public readonly string $status,
        public readonly array $tarifs = [],
        /** La séance propose l'accès payant au direct en ligne (pop-up du site) */
        public readonly bool $liveAccess = false,
        public readonly string $eventId = '',
        /** Données affichées issues du dernier relevé réussi (Tick Africa injoignable depuis) */
        public readonly bool $stale = false,
    ) {
    }

    /** « Samedi 28 novembre » (heure du Bénin) */
    public function jour(): string
    {
        $d = $this->local();

        return sprintf('%s %d %s', ucfirst(self::DAYS[(int) $d->format('w')]), (int) $d->format('j'), self::MONTHS[(int) $d->format('n')]);
    }

    /** « Sam. 28 nov. » */
    public function jourCourt(): string
    {
        $d = $this->local();

        return sprintf('%s. %d %s', ucfirst(mb_substr(self::DAYS[(int) $d->format('w')], 0, 3)), (int) $d->format('j'), self::MONTHS_SHORT[(int) $d->format('n')]);
    }

    /** « 13h00 » */
    public function heure(): string
    {
        return $this->local()->format('G\hi');
    }

    private function local(): \DateTimeImmutable
    {
        return $this->startsAt->setTimezone(new \DateTimeZone('Africa/Porto-Novo'));
    }

    private const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    private const MONTHS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const MONTHS_SHORT = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

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

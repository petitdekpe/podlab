<?php

namespace App\Site;

/**
 * Dates affichées sur le site, en français et à l'heure du Bénin.
 */
final class DateFr
{
    public const TIMEZONE = 'Africa/Porto-Novo';

    private const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    private const MONTHS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const MONTHS_SHORT = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    public static function local(\DateTimeInterface $d): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($d)->setTimezone(self::zone());
    }

    /**
     * Date lue en base : les colonnes contiennent l'heure du Bénin, sans fuseau.
     * Le résultat ne dépend donc pas du fuseau par défaut de PHP.
     */
    public static function relire(\DateTimeInterface $d): \DateTimeImmutable
    {
        return new \DateTimeImmutable($d->format('Y-m-d H:i:s'), self::zone());
    }

    /** Valeur d'un champ datetime-local (heure du Bénin), ou null si elle est invalide. */
    public static function saisie(string $valeur): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $valeur, self::zone());

        return false !== $date && $date->format('Y-m-d\TH:i') === $valeur ? $date : null;
    }

    /** « Dimanche 29 novembre » */
    public static function jour(\DateTimeInterface $d): string
    {
        return ucfirst(self::DAYS[(int) self::local($d)->format('w')]).' '.self::date($d);
    }

    /** « Dim. 29 nov. » */
    public static function jourCourt(\DateTimeInterface $d): string
    {
        return self::semaineCourte($d).' '.self::dateCourte($d);
    }

    /** « Dim. » */
    public static function semaineCourte(\DateTimeInterface $d): string
    {
        return ucfirst(mb_substr(self::DAYS[(int) self::local($d)->format('w')], 0, 3)).'.';
    }

    /** « 29 novembre » */
    public static function date(\DateTimeInterface $d): string
    {
        return self::local($d)->format('j').' '.self::MONTHS[(int) self::local($d)->format('n')];
    }

    /** « 29 nov. » */
    public static function dateCourte(\DateTimeInterface $d): string
    {
        return self::local($d)->format('j').' '.self::moisCourt($d);
    }

    /** « nov. » */
    public static function moisCourt(\DateTimeInterface $d): string
    {
        return self::MONTHS_SHORT[(int) self::local($d)->format('n')];
    }

    /** « 13h00 » */
    public static function heure(\DateTimeInterface $d): string
    {
        return self::local($d)->format('G\hi');
    }

    /** « dimanche 22 novembre 2026 à 12h30 » */
    public static function complet(\DateTimeInterface $d): string
    {
        return sprintf('%s %s à %s', lcfirst(self::jour($d)), self::local($d)->format('Y'), self::heure($d));
    }

    /**
     * « 29 novembre et 6, 13 décembre » : dates distinctes, sans répéter le mois.
     *
     * @param iterable<\DateTimeInterface> $dates
     */
    public static function liste(iterable $dates): string
    {
        $mois = [];
        foreach ($dates as $d) {
            $local = self::local($d);
            $mois[$local->format('Y-m')][(int) $local->format('j')] = self::MONTHS[(int) $local->format('n')];
        }
        ksort($mois);

        $groupes = [];
        foreach ($mois as $jours) {
            ksort($jours);
            $groupes[] = self::enumerer(array_keys($jours), 1 === \count($mois) ? ' et ' : ', ').' '.reset($jours);
        }

        return self::enumerer($groupes, ' et ');
    }

    /** @param list<int|string> $valeurs */
    private static function enumerer(array $valeurs, string $dernierLien): string
    {
        $dernier = (string) array_pop($valeurs);

        return [] === $valeurs ? $dernier : implode(', ', $valeurs).$dernierLien.$dernier;
    }
}

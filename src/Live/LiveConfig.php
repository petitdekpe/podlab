<?php

namespace App\Live;

/**
 * Valeurs PAR DÉFAUT du direct, lues dans l'environnement (.env / .env.local).
 * Les valeurs saisies dans l'espace d'administration (LiveSettings) les remplacent.
 */
final class LiveConfig
{
    public function __construct(
        public readonly string $youtube,
        public readonly string $opensAt,
        public readonly string $endsAt,
        public readonly string $superCodeHash,
        /** Billetterie Tickafrica des accès au direct (pop-up du site) */
        public readonly string $liveTicketUrl,
        /** Identifiant (UUID) de l'événement Tick Africa « accès au direct » */
        public readonly string $tickafricaEventId = '',
    ) {
    }
}

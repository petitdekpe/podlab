<?php

namespace App\Live;

/**
 * Valeurs PAR DÉFAUT lues dans l'environnement (.env / .env.local).
 * Celles saisies dans l'espace d'administration (LiveSettings) les remplacent.
 */
final class LiveConfig
{
    public function __construct(public readonly string $superCodeHash)
    {
    }
}

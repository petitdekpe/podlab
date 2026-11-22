<?php

namespace App\Live;

/**
 * Tick Africa n'a pas pu se prononcer sur un billet : réseau, quota dépassé,
 * clé absente ou refusée, événement mal configuré… Ce n'est jamais la faute
 * du spectateur : on lui demande de réessayer, sans compter d'échec.
 *
 * Pour une commande, $apiError porte le code métier stable de Tick Africa
 * (INSUFFICIENT_STOCK, EVENT_NOT_ON_SALE, VALIDATION_ERROR…) et $fieldErrors
 * le détail par champ d'un VALIDATION_ERROR.
 */
final class TickAfricaUnavailable extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $fieldErrors
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $apiError = '',
        public readonly array $fieldErrors = [],
        /** Message de Tick Africa, destiné à un humain (pour l'administration) */
        public readonly string $apiMessage = '',
    ) {
        parent::__construct($message, $code, $previous);
    }
}

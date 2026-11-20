<?php

namespace App\Live;

/**
 * Tick Africa n'a pas pu se prononcer sur un billet : réseau, quota dépassé,
 * clé absente ou refusée, événement mal configuré… Ce n'est jamais la faute
 * du spectateur : on lui demande de réessayer, sans compter d'échec.
 */
final class TickAfricaUnavailable extends \RuntimeException
{
}

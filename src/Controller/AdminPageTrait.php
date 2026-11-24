<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Commun aux pages de gestion de /admin (accès verrouillé par AdminAccessListener).
 */
trait AdminPageTrait
{
    private const CSRF_ID = 'admin';

    private function csrfOk(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'));
    }

    /** Texte d'un champ, espaces regroupés. */
    private static function texte(Request $request, string $champ): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $request->request->get($champ)) ?? '');
    }

    private static function erreurCapacite(string $valeur): ?string
    {
        return '' !== $valeur && (!ctype_digit($valeur) || (int) $valeur < 1 || (int) $valeur > 100000)
            ? 'La capacité de la salle doit être un nombre entier de places (ex. 180).'
            : null;
    }

    private function page(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

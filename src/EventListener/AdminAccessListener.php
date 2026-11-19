<?php

namespace App\EventListener;

use App\Live\AdminAuth;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Verrou unique de l'espace /admin : toute URL /admin/* exige une session
 * superadmin, sauf la page de connexion. Ainsi une nouvelle page d'admin ne
 * peut pas être exposée par oubli.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final class AdminAccessListener
{
    public const LOGIN_PATH = '/admin/connexion';

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = rawurldecode($request->getPathInfo());

        if (!$event->isMainRequest() || !($path === '/admin' || str_starts_with($path, '/admin/'))) {
            return;
        }

        if (self::LOGIN_PATH === rtrim($path, '/') || $this->auth->isAuthenticated($request->getSession())) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_admin_login')));
    }
}

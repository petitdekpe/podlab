<?php

namespace App\Controller;

use App\Billetterie\Ventes;
use App\Live\LiveSessions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bilan des ventes des séances du 29 novembre (/ventes), ouvert avec un code
 * d'accès (VENTES_CODE). Indépendant de /admin : on peut le confier à
 * l'équipe sans lui donner le supercode.
 */
final class VentesController extends AbstractController
{
    private const CSRF_ID = 'ventes';
    private const SESSION = 'ventes_auth';
    private const IDLE_TIMEOUT = 43200; // 12 h d'inactivité

    public function __construct(
        private readonly Ventes $ventes,
        private readonly LiveSessions $sessions,
        #[\SensitiveParameter]
        private readonly string $code = '',
    ) {
    }

    #[Route('/ventes', name: 'app_ventes', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $error = null;

        if ($request->isMethod('POST')) {
            $key = 'ventes:'.($request->getClientIp() ?? 'inconnue');
            $saisi = preg_replace('/\s+/', '', (string) $request->request->get('code')) ?? '';

            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
                $error = 'Session expirée. Réessayez.';
            } elseif ($this->sessions->isThrottled($key)) {
                $error = 'Trop d\'essais. Patientez quelques minutes avant de réessayer.';
            } elseif ('' !== $this->code && hash_equals($this->code, $saisi)) {
                $this->sessions->clearFailures($key);
                $session->migrate(true);
                $session->set(self::SESSION, ['fp' => $this->empreinte(), 'at' => time()]);

                return $this->redirectToRoute('app_ventes');
            } else {
                $this->sessions->registerFailure($key);
                $error = 'Code incorrect.';
            }
        }

        if (!$this->isAuthenticated($request)) {
            return $this->page($this->render('ventes/login.html.twig', [
                'error' => $error,
                'configured' => '' !== $this->code,
            ], new Response(status: null !== $error ? Response::HTTP_UNAUTHORIZED : Response::HTTP_OK)));
        }

        return $this->page($this->render('ventes/index.html.twig', [
            'r' => $this->ventes->rapport($request->query->getBoolean('actualiser')),
            'couleurs' => ['marina-28' => 1, 'wologuede-28' => 2],
        ]));
    }

    #[Route('/ventes/deconnexion', name: 'app_ventes_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $request->getSession()->remove(self::SESSION);
            $request->getSession()->migrate(true);
        }

        return $this->redirectToRoute('app_ventes');
    }

    private function isAuthenticated(Request $request): bool
    {
        $session = $request->getSession();
        $data = $session->get(self::SESSION);

        // Changer le code ferme les sessions ouvertes avec l'ancien
        if ('' === $this->code || !\is_array($data) || !isset($data['fp'], $data['at'])
            || !hash_equals($this->empreinte(), (string) $data['fp'])
            || time() - (int) $data['at'] > self::IDLE_TIMEOUT) {
            return false;
        }
        $session->set(self::SESSION, ['fp' => $data['fp'], 'at' => time()]);

        return true;
    }

    private function empreinte(): string
    {
        return hash_hmac('sha256', $this->code, (string) $this->getParameter('kernel.secret'));
    }

    private function page(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

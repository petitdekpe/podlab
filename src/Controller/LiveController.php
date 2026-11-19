<?php

namespace App\Controller;

use App\Entity\LiveCode;
use App\Live\LiveCodes;
use App\Live\LiveSessions;
use App\Live\LiveSettings;
use App\Live\SuperCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page de direct : accessible uniquement avec un code de billet (ou le supercode).
 *
 * L'identifiant de la vidéo YouTube n'est jamais dans le HTML : il n'est
 * fourni par /live/flux qu'à un billet valide, et seulement pendant la
 * fenêtre d'ouverture du direct (le supercode y accède à tout moment).
 */
#[Route('/live')]
final class LiveController extends AbstractController
{
    private const SESSION_KEY = 'live_ticket';
    private const CSRF_ID = 'live';

    private const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    private const MONTHS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(
        private readonly LiveSettings $settings,
        private readonly LiveCodes $codes,
        private readonly LiveSessions $sessions,
        private readonly SuperCode $superCode,
    ) {
    }

    #[Route('', name: 'app_live', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $now = new \DateTimeImmutable();
        $opens = $this->settings->opensAt();
        $viewer = $this->viewer($request);

        $etat = match (true) {
            $viewer['super'] => 'direct',
            $now >= $this->settings->endsAt() => 'termine',
            !$this->settings->enabled() => 'ferme',
            $viewer['valid'] && $now < $opens => 'attente',
            $viewer['valid'] => 'direct',
            $viewer['replaced'] => 'remplace',   // billet repris sur un autre appareil
            default => 'verrouille',
        };

        return $this->noStore($this->render('live/index.html.twig', [
            'etat' => $etat,
            'title' => $this->settings->title(),
            'opens_at' => $opens,
            'ends_at' => $this->settings->endsAt(),
            'opens_label' => $this->label($opens),
            'watermark' => $viewer['super'] ? 'Superadmin' : ($this->settings->watermark() && $viewer['code'] ? 'Billet '.LiveCodes::format($viewer['code']->getCode()) : null),
            'is_super' => $viewer['super'],
        ]));
    }

    #[Route('/acces', name: 'app_live_access', methods: ['POST'])]
    public function access(Request $request): Response
    {
        $ip = 'live:'.($request->getClientIp() ?? 'inconnue');

        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('live_error', 'Session expirée. Réessayez.');

            return $this->redirectToRoute('app_live');
        }

        if ($this->sessions->isThrottled($ip)) {
            $this->addFlash('live_error', 'Trop d\'essais. Patientez quelques minutes avant de réessayer.');

            return $this->redirectToRoute('app_live');
        }

        $normalized = LiveCodes::normalize((string) $request->request->get('code'));

        // Supercode
        if ($this->superCode->verify($normalized)) {
            $this->sessions->clearFailures($ip);
            $session = $request->getSession();
            $session->migrate(true);
            $session->set(self::SESSION_KEY, ['type' => 'super', 'fp' => $this->superCode->fingerprint()]);

            return $this->redirectToRoute('app_live');
        }

        $code = $this->codes->find($normalized);

        if (null === $code || $code->isRevoked()) {
            $this->sessions->registerFailure($ip);
            $this->addFlash('live_error', null === $code
                ? 'Ce code n\'est pas valide. Vérifiez-le dans l\'e-mail de votre billet.'
                : 'Ce code n\'est plus valide. Contactez les organisateurs.');

            return $this->redirectToRoute('app_live');
        }

        $this->sessions->clearFailures($ip);
        $this->startTicketSession($request, $code);

        return $this->redirectToRoute('app_live');
    }

    /** Reprend la main sur ce billet (après qu'il a été utilisé sur un autre appareil). */
    #[Route('/reprendre', name: 'app_live_resume', methods: ['POST'])]
    public function resume(Request $request): Response
    {
        $code = $this->viewer($request)['code'];

        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))
            && null !== $code && !$code->isRevoked()) {
            $this->startTicketSession($request, $code);
        }

        return $this->redirectToRoute('app_live');
    }

    #[Route('/sortie', name: 'app_live_exit', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $viewer = $this->viewer($request);
            if ($viewer['valid'] && null !== $viewer['code'] && null !== $viewer['token']) {
                $this->sessions->release($viewer['code'], $viewer['token']);
            }
            $request->getSession()->remove(self::SESSION_KEY);
        }

        return $this->redirectToRoute('app_live');
    }

    /** Identifiant de la vidéo : billet valide + direct ouvert (ou supercode). */
    #[Route('/flux', name: 'app_live_flux', methods: ['POST'])]
    public function flux(Request $request): Response
    {
        $viewer = $this->viewer($request);

        if ($error = $this->guard($request, $viewer)) {
            return $error;
        }

        if (!$viewer['super'] && new \DateTimeImmutable() < $this->settings->opensAt()) {
            return $this->noStore(new JsonResponse(['error' => 'pas_ouvert'], Response::HTTP_FORBIDDEN));
        }

        $videoId = $this->settings->youtubeId();
        if ('' === $videoId) {
            return $this->noStore(new JsonResponse(['error' => 'non_configure'], Response::HTTP_SERVICE_UNAVAILABLE));
        }

        return $this->noStore(new JsonResponse(['videoId' => $videoId]));
    }

    /** Signal de présence : indique si ce billet est toujours celui qui a la main. */
    #[Route('/ping', name: 'app_live_ping', methods: ['POST'])]
    public function ping(Request $request): Response
    {
        $viewer = $this->viewer($request);

        if ($error = $this->guard($request, $viewer)) {
            return $error;
        }

        if (!$viewer['super'] && null !== $viewer['code']) {
            $this->sessions->touch($viewer['code']);
        }

        return $this->noStore(new Response(null, Response::HTTP_NO_CONTENT));
    }

    // ---------------------------------------------------------------------

    /**
     * @param array{super: bool, valid: bool, replaced: bool, code: ?LiveCode, token: ?string} $viewer
     */
    private function guard(Request $request, array $viewer): ?Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->noStore(new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN));
        }

        if ($viewer['super']) {
            return null;
        }

        // L'identité est vérifiée en premier : on ne révèle rien à quelqu'un qui n'a pas de billet
        if (!$viewer['valid'] && !$viewer['replaced']) {
            return $this->noStore(new JsonResponse(['error' => 'non_autorise'], Response::HTTP_UNAUTHORIZED));
        }

        if (new \DateTimeImmutable() >= $this->settings->endsAt()) {
            return $this->noStore(new JsonResponse(['error' => 'termine'], Response::HTTP_GONE));
        }

        if (!$this->settings->enabled()) {
            return $this->noStore(new JsonResponse(['error' => 'ferme'], Response::HTTP_LOCKED));
        }

        if (!$viewer['valid']) {
            return $this->noStore(new JsonResponse(['error' => 'remplace'], Response::HTTP_CONFLICT));
        }

        return null;
    }

    /**
     * Qui est derrière cette session ?
     *
     * @return array{super: bool, valid: bool, replaced: bool, code: ?LiveCode, token: ?string}
     */
    private function viewer(Request $request): array
    {
        $none = ['super' => false, 'valid' => false, 'replaced' => false, 'code' => null, 'token' => null];
        $data = $request->getSession()->get(self::SESSION_KEY);

        if (!\is_array($data)) {
            return $none;
        }

        if (($data['type'] ?? null) === 'super') {
            $ok = $this->superCode->isConfigured() && hash_equals($this->superCode->fingerprint(), (string) ($data['fp'] ?? ''));

            return ['super' => $ok] + $none;
        }

        if (!isset($data['code'], $data['token'])) {
            return $none;
        }

        $code = $this->codes->find((string) $data['code']);

        if (null === $code || $code->isRevoked()) {
            return $none;
        }

        $valid = $this->sessions->isCurrent($code, (string) $data['token']);

        return ['super' => false, 'valid' => $valid, 'replaced' => !$valid, 'code' => $code, 'token' => (string) $data['token']];
    }

    private function startTicketSession(Request $request, LiveCode $code): void
    {
        $session = $request->getSession();
        $session->migrate(true); // nouvel identifiant de session à la connexion
        $session->set(self::SESSION_KEY, [
            'type' => 'ticket',
            'code' => $code->getCode(),
            'token' => $this->sessions->claim($code),
        ]);
    }

    /** "dimanche 22 novembre 2026 à 12h30" (heure du Bénin) */
    private function label(\DateTimeImmutable $date): string
    {
        $local = $date->setTimezone(new \DateTimeZone(LiveSettings::TIMEZONE));

        return sprintf(
            '%s %d %s %d à %s',
            self::DAYS[(int) $local->format('w')],
            (int) $local->format('j'),
            self::MONTHS[(int) $local->format('n')],
            (int) $local->format('Y'),
            $local->format('H\hi'),
        );
    }

    private function noStore(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

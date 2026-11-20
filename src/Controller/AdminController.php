<?php

namespace App\Controller;

use App\Live\AdminAuth;
use App\Live\LiveCodes;
use App\Live\LiveSessions;
use App\Live\LiveSettings;
use App\Live\SuperCode;
use App\Repository\LiveCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace d'administration du direct (superadmin).
 *
 * L'accès est verrouillé pour toutes les URL /admin/* par AdminAccessListener,
 * sauf la page de connexion.
 */
#[Route('/admin')]
final class AdminController extends AbstractController
{
    private const CSRF_ID = 'admin';
    private const MAX_GENERATE = 500;

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly SuperCode $superCode,
        private readonly LiveSettings $settings,
        private readonly LiveCodes $codes,
        private readonly LiveCodeRepository $repository,
        private readonly LiveSessions $sessions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    // --- Connexion ---------------------------------------------------------

    #[Route('/connexion', name: 'app_admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $session = $request->getSession();

        if ($this->auth->isAuthenticated($session)) {
            return $this->redirectToRoute('app_admin');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $key = 'admin:'.($request->getClientIp() ?? 'inconnue');

            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
                $error = 'Session expirée. Réessayez.';
            } elseif ($this->sessions->isThrottled($key)) {
                $error = 'Trop d\'essais. Patientez quelques minutes avant de réessayer.';
            } elseif ($this->superCode->verify(LiveCodes::normalize((string) $request->request->get('supercode')))) {
                $this->sessions->clearFailures($key);
                $this->auth->login($session);

                return $this->redirectToRoute('app_admin');
            } else {
                $this->sessions->registerFailure($key);
                $error = 'Supercode incorrect.';
            }
        }

        return $this->page($this->render('admin/login.html.twig', [
            'error' => $error,
            'configured' => $this->superCode->isConfigured(),
        ]));
    }

    #[Route('/deconnexion', name: 'app_admin_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->auth->logout($request->getSession());
        }

        return $this->redirectToRoute('app_admin_login');
    }

    // --- Tableau de bord ---------------------------------------------------

    #[Route('', name: 'app_admin', methods: ['GET'])]
    public function dashboard(): Response
    {
        $now = new \DateTimeImmutable();
        $opens = $this->settings->opensAt();
        $ends = $this->settings->endsAt();

        return $this->page($this->render('admin/dashboard.html.twig', [
            'stats' => $this->repository->stats($now->modify('-90 seconds')),
            'phase' => match (true) {
                $now >= $ends => 'termine',
                $now >= $opens => 'ouvert',
                default => 'avant',
            },
            'enabled' => $this->settings->enabled(),
            'youtube_ok' => '' !== $this->settings->youtubeId(),
            'opens_at' => $opens,
            'ends_at' => $ends,
        ]));
    }

    // --- Paramètres --------------------------------------------------------

    #[Route('/parametres', name: 'app_admin_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $errors = [];
        $tz = new \DateTimeZone(LiveSettings::TIMEZONE);

        $form = [
            'title' => $this->settings->title(),
            'youtube' => $this->settings->get('youtube') ?? $this->settings->youtubeId(),
            'opens_at' => $this->settings->opensAt()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'ends_at' => $this->settings->endsAt()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'enabled' => $this->settings->enabled(),
            'watermark' => $this->settings->watermark(),
            'soldout' => $this->settings->soldOut(),
            'live_ticket_url' => (string) $this->settings->get('live_ticket_url'),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
                $errors[] = 'Session expirée. Réessayez.';
            } else {
                $form = [
                    'title' => trim((string) $request->request->get('title')),
                    'youtube' => trim((string) $request->request->get('youtube')),
                    'opens_at' => (string) $request->request->get('opens_at'),
                    'ends_at' => (string) $request->request->get('ends_at'),
                    'enabled' => $request->request->getBoolean('enabled'),
                    'watermark' => $request->request->getBoolean('watermark'),
                    'soldout' => $request->request->getBoolean('soldout'),
                    'live_ticket_url' => trim((string) $request->request->get('live_ticket_url')),
                ];

                $opens = $this->parseLocal($form['opens_at'], $tz);
                $ends = $this->parseLocal($form['ends_at'], $tz);

                if (mb_strlen($form['title']) > 120) {
                    $errors[] = 'Le titre ne doit pas dépasser 120 caractères.';
                }
                if ('' !== $form['youtube'] && '' === LiveSettings::extractVideoId($form['youtube'])) {
                    $errors[] = 'Le lien ou l\'identifiant YouTube n\'est pas reconnu (attendu : lien youtube.com/watch?v=…, youtu.be/… ou identifiant de 11 caractères).';
                }
                if ('' !== $form['live_ticket_url'] && '' === LiveSettings::normalizeEmbedUrl($form['live_ticket_url'])) {
                    $errors[] = 'Le lien de la billetterie du direct doit être une adresse complète commençant par https://.';
                }
                if (null === $opens || null === $ends) {
                    $errors[] = 'Les dates d\'ouverture et de fin sont obligatoires.';
                } elseif ($ends <= $opens) {
                    $errors[] = 'La fin du direct doit être après son ouverture.';
                }

                if ([] === $errors && null !== $opens && null !== $ends) {
                    $this->settings->set('title', '' === $form['title'] ? null : $form['title']);
                    $this->settings->set('youtube', '' === $form['youtube'] ? null : LiveSettings::extractVideoId($form['youtube']));
                    $this->settings->set('opens_at', $opens->format('c'));
                    $this->settings->set('ends_at', $ends->format('c'));
                    $this->settings->set('enabled', $form['enabled'] ? '1' : '0');
                    $this->settings->set('watermark', $form['watermark'] ? '1' : '0');
                    $this->settings->set('soldout', $form['soldout'] ? '1' : '0');
                    $this->settings->set('live_ticket_url', '' === $form['live_ticket_url'] ? null : $form['live_ticket_url']);
                    $this->addFlash('admin_ok', 'Paramètres enregistrés.');

                    return $this->redirectToRoute('app_admin_settings');
                }
            }
        }

        return $this->page($this->render('admin/settings.html.twig', [
            'form' => $form,
            'errors' => $errors,
            'timezone' => LiveSettings::TIMEZONE,
            'live_ticket_default' => $this->settings->defaultLiveTicketUrl(),
        ]));
    }

    /** Remplace le supercode (l'ancien cesse de fonctionner immédiatement). */
    #[Route('/supercode', name: 'app_admin_supercode', methods: ['POST'])]
    public function rotateSuperCode(Request $request): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $new = $this->superCode->rotate();
            $this->auth->login($request->getSession()); // cette session reste connectée
            $this->addFlash('admin_supercode', $new); // affiché une seule fois
        }

        return $this->redirectToRoute('app_admin_settings');
    }

    // --- Codes -------------------------------------------------------------

    #[Route('/codes', name: 'app_admin_codes', methods: ['GET'])]
    public function codes(Request $request): Response
    {
        $q = trim((string) $request->query->get('q'));
        $status = (string) $request->query->get('statut', 'all');
        $status = \in_array($status, ['all', 'used', 'unused', 'revoked'], true) ? $status : 'all';
        $page = max(1, $request->query->getInt('page', 1));

        $result = $this->repository->search($q, $status, $page);

        return $this->page($this->render('admin/codes.html.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => min($page, $result['pages']),
            'q' => $q,
            'status' => $status,
            'back' => $request->getRequestUri(),
            'max_generate' => self::MAX_GENERATE,
        ]));
    }

    #[Route('/codes/generer', name: 'app_admin_codes_generate', methods: ['POST'])]
    public function generate(Request $request): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $count = $request->request->getInt('count');

            if ($count < 1 || $count > self::MAX_GENERATE) {
                $this->addFlash('admin_error', sprintf('Choisissez un nombre entre 1 et %d.', self::MAX_GENERATE));
            } else {
                $this->codes->generate($count);
                $this->addFlash('admin_ok', sprintf('%d code(s) ajouté(s) à la fin de la liste.', $count));
            }
        }

        return $this->redirectToRoute('app_admin_codes');
    }

    #[Route('/codes/export.csv', name: 'app_admin_codes_export', methods: ['GET'])]
    public function export(): Response
    {
        $lines = ["numero;code;libelle;statut"];
        foreach ($this->repository->allOrdered() as $i => $code) {
            $lines[] = implode(';', [
                $i + 1,
                LiveCodes::format($code->getCode()),
                $this->csvCell((string) $code->getLabel()),
                $code->isRevoked() ? 'révoqué' : (null === $code->getFirstUsedAt() ? 'non utilisé' : 'utilisé'),
            ]);
        }

        $response = new Response("\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n"); // BOM : Excel lit bien l'UTF-8
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="codes-direct.csv"');

        return $this->page($response);
    }

    #[Route('/codes/{id}/libelle', name: 'app_admin_code_label', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function label(int $id, Request $request): Response
    {
        $code = $this->repository->find($id);

        if (null !== $code && $this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $code->setLabel((string) $request->request->get('label'));
            $this->em->flush();
        }

        return $this->back($request);
    }

    #[Route('/codes/{id}/revoquer', name: 'app_admin_code_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(int $id, Request $request): Response
    {
        $code = $this->repository->find($id);

        if (null !== $code && $this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $code->isRevoked() ? $code->restore() : $code->revoke();
            $this->em->flush();
        }

        return $this->back($request);
    }

    /** Retire la main à l'appareil connecté : la personne devra « Reprendre ici ». */
    #[Route('/codes/{id}/deconnecter', name: 'app_admin_code_disconnect', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function disconnect(int $id, Request $request): Response
    {
        $code = $this->repository->find($id);

        if (null !== $code && $this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $code->setClaimToken(null);
            $this->em->flush();
        }

        return $this->back($request);
    }

    // ---------------------------------------------------------------------

    private function parseLocal(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $tz);

        return false === $date ? null : $date;
    }

    /** Neutralise les formules (=, +, -, @) pour les tableurs. */
    private function csvCell(string $value): string
    {
        $value = str_replace([';', "\r", "\n"], [',', ' ', ' '], $value);

        return '' !== $value && str_contains('=+-@', $value[0]) ? "'".$value : $value;
    }

    /** Retour à la liste d'où l'on vient (uniquement vers /admin/codes). */
    private function back(Request $request): Response
    {
        $back = (string) $request->request->get('back');

        return str_starts_with($back, '/admin/codes') && !str_contains($back, '//')
            ? $this->redirect($back)
            : $this->redirectToRoute('app_admin_codes');
    }

    private function page(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

<?php

namespace App\Controller;

use App\Billetterie\Billetterie;
use App\Live\AdminAuth;
use App\Live\TickAfricaUnavailable;
use App\Live\LiveSessions;
use App\Live\LiveSettings;
use App\Live\SuperCode;
use App\Live\TickAfrica;
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

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly SuperCode $superCode,
        private readonly LiveSettings $settings,
        private readonly TickAfrica $tickAfrica,
        private readonly LiveCodeRepository $repository,
        private readonly LiveSessions $sessions,
        private readonly EntityManagerInterface $em,
        private readonly Billetterie $billetterie,
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
            } elseif ($this->superCode->verify(SuperCode::normalize((string) $request->request->get('supercode')))) {
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
            'tickafrica_key' => $this->tickAfrica->isConfigured(),
            'tickafrica_test' => $this->tickAfrica->isTestMode(),
            'tickafrica_event' => '' !== $this->settings->tickafricaEventId(),
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
            'tickafrica_event_id' => (string) $this->settings->get('tickafrica_event_id'),
            'seances' => $this->seanceFields(),
        ];
        $warnings = [];

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
                    'tickafrica_event_id' => trim((string) $request->request->get('tickafrica_event_id')),
                    'seances' => $this->seanceFields($request->request->all('seances')),
                ];
                // La clé n'est jamais réaffichée : champ vide = on garde celle enregistrée
                $apiKey = trim((string) $request->request->get('tickafrica_api_key'));
                $clearKey = $request->request->getBoolean('tickafrica_api_key_clear');

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
                if ('' !== $form['tickafrica_event_id'] && '' === LiveSettings::normalizeUuid($form['tickafrica_event_id'])) {
                    $errors[] = 'L\'identifiant de l\'événement Tick Africa doit être un UUID (ex. 9a8b7c6d-5e4f-3a2b-1c0d-9e8f7a6b5c4d).';
                }
                if (null === $opens || null === $ends) {
                    $errors[] = 'Les dates d\'ouverture et de fin sont obligatoires.';
                } elseif ($ends <= $opens) {
                    $errors[] = 'La fin du direct doit être après son ouverture.';
                }

                foreach ($form['seances'] as $key => $seance) {
                    if ('' !== $seance['event'] && '' === LiveSettings::normalizeUuid($seance['event'])) {
                        $errors[] = sprintf('Séance « %s » : l\'identifiant de l\'événement doit être un UUID.', $seance['label']);
                    }
                    if ('' !== $seance['tarifs'] && '' === $seance['event']) {
                        $errors[] = sprintf('Séance « %s » : indiquez l\'événement avant de restreindre les billets.', $seance['label']);
                    }
                    foreach (array_filter(array_map('trim', explode(',', $seance['tarifs']))) as $tarif) {
                        if ('' === LiveSettings::normalizeUuid($tarif)) {
                            $errors[] = sprintf('Séance « %s » : « %s » n\'est pas un identifiant de billet (UUID).', $seance['label'], $tarif);
                        }
                    }
                }

                if ('' !== $apiKey && !$clearKey) {
                    $keyError = $this->checkApiKey($apiKey, $warnings);
                    if (null !== $keyError) {
                        $errors[] = $keyError;
                    }
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
                    $this->settings->set('tickafrica_event_id', LiveSettings::normalizeUuid($form['tickafrica_event_id']) ?: null);
                    foreach ($form['seances'] as $key => $seance) {
                        $this->settings->set('seance_'.$key, self::seanceLink($seance) ?: null);
                    }
                    if ($clearKey) {
                        $this->settings->setTickafricaApiKey(null);
                    } elseif ('' !== $apiKey) {
                        $this->settings->setTickafricaApiKey($apiKey);
                    }
                    $this->addFlash('admin_ok', 'Paramètres enregistrés.');
                    foreach ($warnings as $warning) {
                        $this->addFlash('admin_error', $warning);
                    }

                    return $this->redirectToRoute('app_admin_settings');
                }
            }
        }

        return $this->page($this->render('admin/settings.html.twig', [
            'form' => $form,
            'errors' => $errors,
            'timezone' => LiveSettings::TIMEZONE,
            'live_ticket_default' => $this->settings->defaultLiveTicketUrl(),
            'tickafrica_event_default' => LiveSettings::normalizeUuid($this->settings->tickafricaEventIdDefault()),
            'tickafrica_key' => $this->tickAfrica->isConfigured(),
            'tickafrica_test' => $this->tickAfrica->isTestMode(),
            'tickafrica_key_source' => $this->tickAfrica->keySource(),
            'tickafrica_key_masked' => $this->tickAfrica->maskedKey(),
            'tickafrica_key_unreadable' => $this->settings->tickafricaApiKeyUnreadable(),
            'can_store_secrets' => $this->settings->canStoreSecrets(),
            'tickafrica_events' => $this->tickafricaEvents(),
            'seances_etat' => $this->billetterie->seances(),
        ]));
    }

    /**
     * Champs « séances » du formulaire : saisie en cours, sinon valeurs enregistrées.
     *
     * @param array<string, mixed>|null $posted
     *
     * @return array<string, array{label: string, event: string, tarifs: string, default: string}>
     */
    private function seanceFields(?array $posted = null): array
    {
        $labels = ['marina-22' => 'Dim. 22 nov. · Majestic Marina', 'marina-28' => 'Dim. 29 nov. · Majestic Marina', 'wologuede-28' => 'Dim. 29 nov. · Majestic Wologuèdè'];
        $defaults = $this->billetterie->defaultLinks();
        $fields = [];

        foreach (LiveSettings::SEANCES as $key) {
            if (null !== $posted) {
                $event = trim((string) ($posted[$key]['event'] ?? ''));
                $tarifs = trim((string) ($posted[$key]['tarifs'] ?? ''));
            } else {
                [$event, $ids] = Billetterie::parseLink($this->settings->seanceLink($key));
                $tarifs = implode(', ', $ids);
            }
            $fields[$key] = ['label' => $labels[$key], 'event' => $event, 'tarifs' => $tarifs, 'default' => $defaults[$key] ?? ''];
        }

        return $fields;
    }

    /** @param array{event: string, tarifs: string} $seance */
    private static function seanceLink(array $seance): string
    {
        $event = LiveSettings::normalizeUuid($seance['event']);
        if ('' === $event) {
            return '';
        }
        $tarifs = array_filter(array_map(static fn (string $t): string => LiveSettings::normalizeUuid($t), explode(',', $seance['tarifs'])));

        return [] === $tarifs ? $event : $event.':'.implode(',', $tarifs);
    }

    /**
     * Vérifie une nouvelle clé auprès de Tick Africa avant de l'enregistrer.
     *
     * @param list<string> $warnings avertissements (la clé est tout de même enregistrée)
     *
     * @return string|null erreur bloquante
     */
    private function checkApiKey(#[\SensitiveParameter] string $apiKey, array &$warnings): ?string
    {
        if (!preg_match('/^ta_(live|test)_[A-Za-z0-9_\-]{8,}$/', $apiKey)) {
            return 'La clé API Tick Africa doit commencer par ta_live_ (production) ou ta_test_ (test).';
        }
        if (!$this->settings->canStoreSecrets()) {
            return 'Impossible d\'enregistrer la clé : APP_SECRET n\'est pas défini sur le serveur (fichier .env.local). Définissez-le, ou utilisez la variable TICKAFRICA_API_KEY.';
        }

        try {
            $this->tickAfrica->withApiKey($apiKey)->events();
        } catch (TickAfricaUnavailable $e) {
            if (401 === $e->getCode()) {
                return 'Tick Africa refuse cette clé (inconnue, révoquée ou expirée). Rien n\'a été enregistré.';
            }
            $warnings[] = 403 === $e->getCode()
                ? 'Clé enregistrée, mais il lui manque la portée events:read : les séances ne pourront pas afficher leurs places. Dans le back-office Tick Africa, donnez-lui les portées events:read, orders:write, orders:read et tickets:validate.'
                : 'Clé enregistrée sans avoir pu être vérifiée : Tick Africa ne répond pas pour le moment.';
        }

        return null;
    }

    /**
     * Événements Tick Africa de la clé, pour proposer leurs identifiants dans le formulaire.
     *
     * @return list<array{id: string, label: string}>
     */
    private function tickafricaEvents(): array
    {
        if (!$this->tickAfrica->isConfigured()) {
            return [];
        }
        try {
            $events = $this->tickAfrica->events();
        } catch (TickAfricaUnavailable) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (array $e): array => [
            'id' => (string) ($e['id'] ?? ''),
            'label' => trim(sprintf('%s · %s', $e['name'] ?? '', substr((string) ($e['starts_at'] ?? ''), 0, 16))),
        ], $events), static fn (array $e): bool => '' !== $e['id']));
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
        $status = \in_array($status, ['all', 'used', 'revoked'], true) ? $status : 'all';
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
        ]));
    }

    #[Route('/codes/export.csv', name: 'app_admin_codes_export', methods: ['GET'])]
    public function export(): Response
    {
        $lines = ["numero;code;libelle;statut;premiere_connexion"];
        foreach ($this->repository->allOrdered() as $i => $code) {
            $lines[] = implode(';', [
                $i + 1,
                $this->csvCell($code->getCode()),
                $this->csvCell((string) $code->getLabel()),
                $code->isRevoked() ? 'révoqué' : 'actif',
                $code->getFirstUsedAt()?->setTimezone(new \DateTimeZone(LiveSettings::TIMEZONE))->format('d/m/Y H:i') ?? '',
            ]);
        }

        $response = new Response("\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n"); // BOM : Excel lit bien l'UTF-8
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="billets-direct.csv"');

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

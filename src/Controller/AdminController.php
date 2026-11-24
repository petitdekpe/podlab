<?php

namespace App\Controller;

use App\Billetterie\Billetterie;
use App\Billetterie\Seance;
use App\Entity\Direct;
use App\Live\AdminAuth;
use App\Live\TickAfricaUnavailable;
use App\Live\LiveSessions;
use App\Live\LiveSettings;
use App\Live\SuperCode;
use App\Live\TickAfrica;
use App\Programme\Programme;
use App\Repository\LiveCodeRepository;
use App\Site\Affiche;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace d'administration (superadmin) : connexion, tableau de bord, paramètres, billets.
 * Évènements et directs : AdminEvenementController et AdminDirectController.
 *
 * L'accès est verrouillé pour toutes les URL /admin/* par AdminAccessListener,
 * sauf la page de connexion.
 */
#[Route('/admin')]
final class AdminController extends AbstractController
{
    use AdminPageTrait;

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly SuperCode $superCode,
        private readonly LiveSettings $settings,
        private readonly TickAfrica $tickAfrica,
        private readonly LiveCodeRepository $repository,
        private readonly LiveSessions $sessions,
        private readonly EntityManagerInterface $em,
        private readonly Billetterie $billetterie,
        private readonly Affiche $affiche,
        private readonly Programme $programme,
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
        $direct = $this->programme->directActuel($now);

        return $this->page($this->render('admin/dashboard.html.twig', [
            'direct' => $direct,
            'phase' => $direct?->phase($now),
            'stats' => null === $direct ? null : $this->repository->stats($now->modify('-90 seconds'), $direct),
            'seances' => array_filter($this->billetterie->seances(), static fn (Seance $s): bool => $s->aVenir()),
            'tickafrica_key' => $this->tickAfrica->isConfigured(),
            'tickafrica_test' => $this->tickAfrica->isTestMode(),
        ]));
    }

    // --- Paramètres --------------------------------------------------------

    #[Route('/parametres', name: 'app_admin_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $errors = [];

        $form = [
            'return_url' => $this->settings->returnUrl(),
            'analytics_id' => $this->settings->analyticsId(),
        ];
        $warnings = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
                $errors[] = 'Session expirée. Réessayez.';
            } else {
                $form = [
                    'return_url' => trim((string) $request->request->get('return_url')),
                    'analytics_id' => trim((string) $request->request->get('analytics_id')),
                ];
                // La clé n'est jamais réaffichée : champ vide = on garde celle enregistrée
                $apiKey = trim((string) $request->request->get('tickafrica_api_key'));
                $clearKey = $request->request->getBoolean('tickafrica_api_key_clear');

                if ('' !== $form['return_url'] && (!str_starts_with($form['return_url'], 'https://') || false === filter_var($form['return_url'], \FILTER_VALIDATE_URL))) {
                    $errors[] = 'L\'URL de retour après paiement doit être une adresse complète commençant par https://.';
                }

                if ('' !== $form['analytics_id'] && '' === LiveSettings::normalizeAnalyticsId($form['analytics_id'])) {
                    $errors[] = 'L\'identifiant de mesure Google Analytics commence par « G- » (ex. G-AB12CD34EF).';
                }

                if ('' !== $apiKey && !$clearKey) {
                    $keyError = $this->checkApiKey($apiKey, $warnings);
                    if (null !== $keyError) {
                        $errors[] = $keyError;
                    }
                }

                if ([] === $errors) {
                    $this->settings->set('return_url', $form['return_url'] ?: null);
                    $this->settings->set('analytics_id', LiveSettings::normalizeAnalyticsId($form['analytics_id']) ?: null);
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
            'affiche' => $this->affiche->personnalisee(),
            'tickafrica_key' => $this->tickAfrica->isConfigured(),
            'tickafrica_test' => $this->tickAfrica->isTestMode(),
            'tickafrica_key_source' => $this->tickAfrica->keySource(),
            'tickafrica_key_masked' => $this->tickAfrica->maskedKey(),
            'tickafrica_key_unreadable' => $this->settings->tickafricaApiKeyUnreadable(),
            'can_store_secrets' => $this->settings->canStoreSecrets(),
            'derniere_erreur' => $this->lastOrderFailure(),
            'return_url_default' => LiveSettings::defaultReturnUrl($this->generateUrl('app_billetterie_merci', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)),
            'diagnostic' => $request->getSession()->get('tickafrica_diagnostic'),
        ]));
    }

    /** Remplace l'affiche de l'accueil, ou revient à celle d'origine. */
    #[Route('/affiche', name: 'app_admin_affiche', methods: ['POST'])]
    public function affiche(Request $request): Response
    {
        $envoi = $request->files->get('affiche');

        if (0 === $request->request->count() && (int) $request->server->get('CONTENT_LENGTH') > 0) {
            // Au-delà de post_max_size, PHP vide tout le formulaire
            $erreur = 'Image trop lourde pour le serveur. Réduisez-la puis réessayez.';
        } elseif (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $erreur = 'Session expirée. Réessayez.';
        } elseif ($request->request->getBoolean('retablir')) {
            $erreur = $this->affiche->retablir() ? null : 'L\'affiche n\'a pas pu être retirée (droits d\'écriture sur le dossier var/).';
        } elseif (!$envoi instanceof UploadedFile) {
            $erreur = 'Choisissez une image.';
        } else {
            $erreur = $this->affiche->remplacer($envoi);
        }

        null === $erreur ? $this->addFlash('affiche_ok', 'L\'affiche du site est à jour.') : $this->addFlash('affiche_error', $erreur);

        return $this->redirect($this->generateUrl('app_admin_settings').'#affiche');
    }

    /** Vérifie les autorisations de la clé auprès de Tick Africa (rien n'est créé ni consommé). */
    #[Route('/tickafrica/verifier', name: 'app_admin_tickafrica_check', methods: ['POST'])]
    public function checkTickAfrica(Request $request): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $request->getSession()->set('tickafrica_diagnostic', [
                'at' => (new \DateTimeImmutable('now', new \DateTimeZone(LiveSettings::TIMEZONE)))->format('d/m/Y H:i'),
                'key' => $this->tickAfrica->maskedKey(),
                'results' => $this->tickAfrica->diagnose(),
            ]);
        }

        return $this->redirect($this->generateUrl('app_admin_settings').'#tickafrica-diagnostic');
    }

    /** @return array<string, mixed>|null */
    private function lastOrderFailure(): ?array
    {
        $failure = $this->billetterie->lastFailure();
        if (null === $failure) {
            return null;
        }

        return $failure + [
            'when' => (new \DateTimeImmutable($failure['at']))->setTimezone(new \DateTimeZone(LiveSettings::TIMEZONE))->format('d/m/Y à H:i'),
            'explication' => Billetterie::explain($failure['error'], (int) $failure['status']),
        ];
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

        $direct = $this->direct($request);
        $result = $this->repository->search($q, $status, $page, $direct);

        return $this->page($this->render('admin/codes.html.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'page' => min($page, $result['pages']),
            'q' => $q,
            'status' => $status,
            'direct' => $direct,
            'directs' => $this->programme->tousDirects(),
            'back' => $request->getRequestUri(),
        ]));
    }

    #[Route('/codes/export.csv', name: 'app_admin_codes_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $lines = ["numero;direct;code;libelle;statut;premiere_connexion"];
        foreach ($this->repository->allOrdered($this->direct($request)) as $i => $code) {
            $lines[] = implode(';', [
                $i + 1,
                $this->csvCell((string) $code->getDirect()?->titre),
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

    /** Direct choisi dans la liste (?direct=…), ou null : tous. */
    private function direct(Request $request): ?Direct
    {
        $id = $request->query->getInt('direct');

        return $id > 0 ? $this->em->find(Direct::class, $id) : null;
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
}

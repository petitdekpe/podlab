<?php

namespace App\Controller;

use App\Billetterie\Billetterie;
use App\Billetterie\Commande;
use App\Billetterie\Seance;
use App\Entity\Direct;
use App\Live\LiveSettings;
use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use App\Programme\Programme;
use App\Site\Affiche;
use App\Site\DateFr;
use App\Site\PhotosLieux;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class HomeController extends AbstractController
{
    private const CSRF_ID = 'billetterie';
    private const SESSION_ORDER = 'billetterie_commande';

    public function __construct(
        private readonly LiveSettings $settings,
        private readonly Billetterie $billetterie,
        private readonly TickAfrica $tickAfrica,
        private readonly Affiche $affiche,
        private readonly Programme $programme,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $commande = new Commande();

        // Lien direct vers une séance : /?seance=wologuede-28#reservation
        $seance = $this->billetterie->seances()[(string) $request->query->get('seance')] ?? null;
        if (null !== $seance && 1 === \count($seance->tarifsEnVente())) {
            $commande->billet = $seance->key.'|'.$seance->tarifsEnVente()[0]->id;
        }

        return $this->page($commande);
    }

    #[Route('/billetterie', name: 'app_billetterie_commande', methods: ['POST'])]
    public function commander(Request $request): Response
    {
        $commande = Commande::fromRequest($request);

        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $commande->errors['form'] = 'La page a expiré. Vérifiez vos informations et validez à nouveau.';

            return $this->page($commande, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $seances = $this->billetterie->seances();
        if (!$commande->validate($seances)) {
            return $this->page($commande, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $seance = $seances[$commande->seanceKey()];
        $tarif = $seance->tarif($commande->tarifId());

        $retour = $this->returnUrl();
        try {
            try {
                $paiement = $this->billetterie->commander($commande, $retour);
            } catch (TickAfricaUnavailable $e) {
                // Clé déjà utilisée avec d'autres données (l'acheteur a corrigé sa saisie après
                // une première tentative sans réponse) : c'est une nouvelle commande, nouvelle clé
                if ('IDEMPOTENCY_KEY_REUSED' !== $e->apiError) {
                    throw $e;
                }
                $commande->idempotencyKey = Commande::newKey();
                $paiement = $this->billetterie->commander($commande, $retour);
            }
        } catch (TickAfricaUnavailable $e) {
            $this->billetterie->recordFailure($e, $commande->seanceKey(), $retour);
            $commande->errors = Billetterie::fieldErrors($e);
            $commande->errors['form'] = Billetterie::message($e);
            // Refus métier : la prochaine tentative est une nouvelle commande.
            // Panne réseau : on garde la clé, Tick Africa rendra le paiement déjà ouvert s'il existe.
            if ('' !== $e->apiError) {
                $commande->idempotencyKey = Commande::newKey();
            }

            return $this->page($commande, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->getSession()->set(self::SESSION_ORDER, [
            'order_id' => $paiement['order_id'],
            'seance' => $commande->seanceKey(),
            'email' => $commande->email,
            // Mesure d'audience (Google Analytics) : aucune donnée sur l'acheteur
            'achat' => [
                'currency' => 'XOF',
                'value' => $tarif->price * $commande->quantity,
                'items' => [$seance->article($tarif) + ['quantity' => $commande->quantity]],
            ],
        ]);

        // Le paiement se déroule chez Tick Africa, qui renvoie ensuite vers /billetterie/merci
        return new RedirectResponse($paiement['payment_url'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/billetterie/merci', name: 'app_billetterie_merci', methods: ['GET'])]
    public function merci(Request $request): Response
    {
        $derniere = $request->getSession()->get(self::SESSION_ORDER);
        $seance = \is_array($derniere) ? ($this->billetterie->seances()[$derniere['seance'] ?? ''] ?? null) : null;

        // pending/processing : paiement pas encore confirmé ; validated : billets émis ; cancelled : non abouti
        $statut = null;
        if (\is_array($derniere) && '' !== ($derniere['order_id'] ?? '')) {
            try {
                $statut = (string) ($this->tickAfrica->order($derniere['order_id'])['status'] ?? '') ?: null;
            } catch (TickAfricaUnavailable) {
                // clé sans la portée orders:read, ou Tick Africa injoignable : message générique
            }
        }

        $response = $this->render('home/merci.html.twig', [
            'statut' => $statut,
            'seance' => $seance,
            'email' => \is_array($derniere) ? ($derniere['email'] ?? null) : null,
            'relance' => \in_array($statut, ['pending', 'processing'], true) && $request->query->getInt('n') < 6,
            'n' => $request->query->getInt('n') + 1,
            // Achat compté seulement si le paiement est confirmé, jamais avec une clé de test
            'achat' => 'validated' === $statut && isset($derniere['achat']) && !$this->tickAfrica->isTestMode()
                ? ['transaction_id' => $derniere['order_id']] + $derniere['achat']
                : null,
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /** Affiche envoyée depuis /admin. L'adresse change avec l'image (?v=) : cache long sans risque. */
    #[Route('/affiche.jpg', name: 'app_affiche', methods: ['GET'])]
    public function affiche(): Response
    {
        return $this->image($this->affiche->fichier() ?? throw $this->createNotFoundException());
    }

    /** Photo d'un lieu envoyée depuis /admin. Chaque photo a son adresse : cache long sans risque. */
    #[Route('/lieux/{fichier}', name: 'app_lieu_photo', requirements: ['fichier' => PhotosLieux::MOTIF], methods: ['GET'])]
    public function photoLieu(string $fichier, PhotosLieux $photos): Response
    {
        return $this->image($photos->fichier($fichier) ?? throw $this->createNotFoundException());
    }

    private function image(string $fichier): Response
    {
        $response = new BinaryFileResponse($fichier, headers: ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff']);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        return $response;
    }

    /**
     * Texte de /admin, ou texte d'origine si la base est indisponible.
     *
     * @param \Closure(): string $lire
     */
    private function texteLieux(\Closure $lire, string $defaut): string
    {
        try {
            return $lire();
        } catch (\Throwable) {
            return $defaut;
        }
    }

    /**
     * Où Tick Africa renvoie l'acheteur après paiement. Doit être IDENTIQUE à une URL
     * enregistrée sur la clé : celle saisie dans /admin, sinon /billetterie/merci en https.
     */
    private function returnUrl(): string
    {
        try {
            $url = $this->settings->returnUrl();
        } catch (\Throwable) {
            $url = '';
        }

        return '' !== $url ? $url : LiveSettings::defaultReturnUrl($this->generateUrl('app_billetterie_merci', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    private function page(Commande $commande, int $status = Response::HTTP_OK): Response
    {
        $now = new \DateTimeImmutable();
        $seances = $this->billetterie->seances();
        $prochaine = $this->billetterie->prochaine();
        $directs = $this->programme->directsAVenir($now);
        $enVente = array_filter($seances, static fn (Seance $s): bool => $s->onSale());
        $aVenir = array_filter($seances, static fn (Seance $s): bool => $s->aVenir());
        $dates = static fn (array $liste): string => DateFr::liste(array_map(static fn (Seance $s): \DateTimeImmutable => $s->startsAt, $liste));

        $salles = [];
        foreach ($seances as $seance) {
            $salles[$seance->salle['name']]['lieu'] = $seance->evenement->lieu;
            $salles[$seance->salle['name']]['seances'][] = $seance;
        }

        $response = $this->render('home/index.html.twig', [
            'seances' => $seances,
            'a_venir' => $aVenir,
            'dates_a_venir' => $dates($aVenir),
            'dates_en_vente' => $dates($enVente),
            'salles' => $salles,
            'lieux_titre' => $this->texteLieux(fn (): string => $this->settings->lieuxTitre(), LiveSettings::LIEUX_TITRE),
            'lieux_phrase' => $this->texteLieux(fn (): string => $this->settings->lieuxPhrase(), LiveSettings::LIEUX_PHRASE),
            'affiche' => $this->affiche->personnalisee(),
            // Spectacle à l'affiche : celui de la prochaine séance, sinon de la dernière
            'vedette' => $prochaine ?? (array_values($seances)[\count($seances) - 1] ?? null),
            'prochaine' => $prochaine,
            'en_vente' => [] !== $enVente,
            'decompte' => $this->decompte($prochaine?->startsAt ?? $now),
            'commande' => $commande,
            'csrf_id' => self::CSRF_ID,
            'directs' => $directs,
            // Pendant le direct, un bandeau mène à /live (la billetterie des séances reste accessible)
            'direct_en_cours' => array_values(array_filter($directs, static fn (Direct $d): bool => Direct::EN_COURS === $d->phase($now)))[0] ?? null,
        ], new Response(status: $status));

        // Places restantes en temps réel : pas de cache navigateur ni intermédiaire
        $response->setPrivate();
        $response->setMaxAge(0);

        return $response;
    }

    /**
     * Server-side initial value of the countdown, so the page renders
     * correctly before (or without) JavaScript.
     *
     * @return array{ended: bool, d: string, h: string, m: string, s: string}
     */
    private function decompte(\DateTimeImmutable $debut): array
    {
        $sec = max(0, $debut->getTimestamp() - time());
        $pad = static fn (int $n): string => str_pad((string) $n, 2, '0', \STR_PAD_LEFT);

        return [
            'ended' => 0 === $sec,
            'd' => $pad(intdiv($sec, 86400)),
            'h' => $pad(intdiv($sec % 86400, 3600)),
            'm' => $pad(intdiv($sec % 3600, 60)),
            's' => $pad($sec % 60),
        ];
    }
}

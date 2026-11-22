<?php

namespace App\Controller;

use App\Billetterie\Billetterie;
use App\Billetterie\Commande;
use App\Live\LiveSettings;
use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        if (!$commande->validate($this->billetterie->seances())) {
            return $this->page($commande, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $paiement = $this->billetterie->commander(
                $commande,
                $this->generateUrl('app_billetterie_merci', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (TickAfricaUnavailable $e) {
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
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function page(Commande $commande, int $status = Response::HTTP_OK): Response
    {
        // Réglages modifiables dans /admin. Si la base est indisponible, la page reste
        // affichée avec les valeurs par défaut (pas de billetterie du direct, filigrane).
        try {
            $liveTicketUrl = $this->settings->liveTicketUrl();
            $watermark = $this->settings->watermark();
            $ouvertureDirect = $this->settings->opensAt();
            $finDirect = $this->settings->endsAt();
        } catch (\Throwable) {
            $watermark = true;
            $liveTicketUrl = $this->settings->defaultLiveTicketUrl(); // ne lit pas la base
            $ouvertureDirect = $finDirect = null;
        }

        $now = new \DateTimeImmutable();
        $seances = $this->billetterie->seances();
        $prochaine = $this->billetterie->prochaine();

        // Le direct du 22 est proposé tant qu'il n'est pas terminé
        $seanceDirect = null;
        foreach ($seances as $seance) {
            if ($seance->liveAccess) {
                $seanceDirect = $seance;
            }
        }

        $response = $this->render('home/index.html.twig', [
            'seances' => $seances,
            'prochaine' => $prochaine,
            'en_vente' => $this->billetterie->enVente(),
            'decompte' => $this->decompte($prochaine?->startsAt ?? $now),
            'commande' => $commande,
            'csrf_id' => self::CSRF_ID,
            'seance_direct' => $seanceDirect,
            // Pendant le direct, un bandeau mène à /live (la billetterie des séances reste accessible)
            'direct_en_cours' => null !== $ouvertureDirect && null !== $finDirect && $now >= $ouvertureDirect && $now < $finDirect,
            'live_ticket_url' => $liveTicketUrl,
            'watermark' => $watermark,
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

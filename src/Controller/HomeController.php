<?php

namespace App\Controller;

use App\Live\LiveSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    private const DEBUT_PROJECTION = '2026-11-22T13:00:00+01:00';
    private const PRIX_BILLET = 10000;

    public function __construct(private readonly LiveSettings $settings)
    {
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        $debut = new \DateTimeImmutable(self::DEBUT_PROJECTION);
        // De l'ouverture du direct à sa fin (réglables dans /admin), le site renvoie vers /live
        $redirection = $finDirect = null;

        // Réglages modifiables dans /admin. Si la base est indisponible, la page
        // reste affichée avec les valeurs par défaut (salle complète, pas de billetterie du direct).
        try {
            $soldOut = $this->settings->soldOut();
            $liveTicketUrl = $this->settings->liveTicketUrl();
            $watermark = $this->settings->watermark();
            $redirection = $this->settings->opensAt();
            $finDirect = $this->settings->endsAt();
        } catch (\Throwable) {
            $watermark = true;
            $soldOut = true;
            $liveTicketUrl = $this->settings->defaultLiveTicketUrl(); // ne lit pas la base
        }

        // Pendant le direct, le site mène directement à la page du direct.
        // Après sa fin, on ne redirige plus (la page « terminé » renvoie vers le site).
        $now = new \DateTimeImmutable();
        $redirige = null !== $redirection && null !== $finDirect && $finDirect > $redirection;

        if ($redirige && $now >= $redirection && $now < $finDirect) {
            $response = $this->redirectToRoute('app_live');
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');

            return $response;
        }

        return $this->render('home/index.html.twig', [
            // Les visiteurs déjà sur la page partent vers /live à l'heure dite
            'redirection_direct' => $redirige && $now < $redirection ? $redirection : null,
            'debut_projection' => $debut,
            'decompte' => $this->decompte($debut),
            'prix_billet' => self::PRIX_BILLET,
            'soldout' => $soldOut,
            'live_ticket_url' => $liveTicketUrl,
            'watermark' => $watermark,
        ]);
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

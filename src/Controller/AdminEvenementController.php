<?php

namespace App\Controller;

use App\Billetterie\Billetterie;
use App\Entity\Evenement;
use App\Entity\Lieu;
use App\Live\LiveSettings;
use App\Live\TickAfrica;
use App\Programme\Programme;
use App\Site\DateFr;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Gestion des évènements (séances) : ajout, modification, duplication, masquage, suppression.
 */
#[Route('/admin/evenements')]
final class AdminEvenementController extends AbstractController
{
    use AdminPageTrait;

    private const CASES = ['complet', 'publie', 'bilan'];

    public function __construct(
        private readonly Programme $programme,
        private readonly Billetterie $billetterie,
        private readonly TickAfrica $tickAfrica,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_admin_evenements', methods: ['GET'])]
    public function index(): Response
    {
        $now = new \DateTimeImmutable();
        $evenements = $this->programme->tousEvenements();
        $lignes = array_map(fn (Evenement $e): array => ['e' => $e, 'etat' => $this->billetterie->etat($e)], $evenements);

        return $this->page($this->render('admin/evenements/index.html.twig', [
            'a_venir' => array_filter($lignes, static fn (array $l): bool => $l['e']->debut() > $now),
            'passes' => array_reverse(array_filter($lignes, static fn (array $l): bool => $l['e']->debut() <= $now)),
            'hors_base' => $this->programme->horsBase(),
        ]));
    }

    /** Nouvel évènement, vierge, dans un lieu (?lieu=) ou copié d'un autre (?copie=). */
    #[Route('/nouveau', name: 'app_admin_evenement_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $source = $this->em->find(Evenement::class, $request->query->getInt('copie'));
        if (null !== $source) {
            $evenement = $source->copie('');
            $evenement->complet = false;
            // Deux séances ne vendent pas les mêmes billets : le lien Tick Africa est à ressaisir
            $evenement->relier('');
        } else {
            $evenement = new Evenement('', new \DateTimeImmutable('+7 days 13:00', DateFr::zone()));
            $evenement->lieu = $this->em->find(Lieu::class, $request->query->getInt('lieu'));
        }

        return $this->formulaire($request, $evenement);
    }

    #[Route('/{id}', name: 'app_admin_evenement', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(int $id, Request $request): Response
    {
        return $this->formulaire($request, $this->evenement($id));
    }

    #[Route('/{id}/publier', name: 'app_admin_evenement_publier', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publier(int $id, Request $request): Response
    {
        $evenement = $this->evenement($id);
        if ($this->csrfOk($request)) {
            $evenement->publie = !$evenement->publie;
            $this->em->flush();
            $this->addFlash('admin_ok', $evenement->publie ? 'L\'évènement est affiché sur le site.' : 'L\'évènement n\'est plus affiché sur le site.');
        }

        return $this->redirectToRoute('app_admin_evenements');
    }

    #[Route('/{id}/supprimer', name: 'app_admin_evenement_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(int $id, Request $request): Response
    {
        $evenement = $this->evenement($id);
        if ($this->csrfOk($request)) {
            // Un direct rattaché est conservé, sans séance
            foreach ($this->programme->tousDirects() as $direct) {
                if ($direct->evenement === $evenement) {
                    $direct->evenement = null;
                }
            }
            $this->em->remove($evenement);
            $this->em->flush();
            $this->addFlash('admin_ok', 'Évènement supprimé.');
        }

        return $this->redirectToRoute('app_admin_evenements');
    }

    private function formulaire(Request $request, Evenement $evenement): Response
    {
        $erreurs = [];
        $form = $this->valeurs($evenement);

        if ($request->isMethod('POST')) {
            $form = $this->saisie($request);
            $erreurs = $this->csrfOk($request) ? $this->controler($form) : ['Session expirée. Réessayez.'];

            if ([] === $erreurs) {
                $nouveau = null === $evenement->getId();
                $this->appliquer($evenement, $form);
                if ($nouveau) {
                    $evenement->setCle($this->cleLibre($evenement));
                    $this->em->persist($evenement);
                }
                $this->em->flush();
                $this->addFlash('admin_ok', $nouveau ? 'Évènement ajouté.' : 'Évènement enregistré.');

                return $this->redirectToRoute('app_admin_evenements');
            }
        }

        return $this->page($this->render('admin/evenements/form.html.twig', [
            'evenement' => $evenement,
            'form' => $form,
            'erreurs' => $erreurs,
            'etat' => null !== $evenement->getId() ? $this->billetterie->etat($evenement) : null,
            'lieux' => $this->programme->lieux(),
            'tickafrica_events' => $this->tickAfrica->eventChoices(),
        ], new Response(status: [] === $erreurs ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    /** @return array<string, string|bool> */
    private function valeurs(Evenement $e): array
    {
        $form = [];
        foreach (self::CASES as $champ) {
            $form[$champ] = $e->$champ;
        }

        return $form + [
            'titre' => $e->titre,
            'lieu' => (string) $e->lieu?->getId(),
            'debut' => $e->debut()->format('Y-m-d\TH:i'),
            'fin' => $e->fin()?->format('Y-m-d\TH:i') ?? '',
            'portes' => str_replace('h', ':', $e->portes),
            'fermeture' => str_replace('h', ':', $e->fermeture),
            'event' => $e->tickafricaEventId,
            'tarifs' => str_replace(',', ', ', $e->tickafricaTarifs),
            'capacite' => (string) $e->capacite,
        ];
    }

    /** @return array<string, string|bool> */
    private function saisie(Request $request): array
    {
        $form = [];
        foreach (['titre', 'lieu', 'debut', 'fin', 'portes', 'fermeture', 'event', 'tarifs'] as $champ) {
            $form[$champ] = self::texte($request, $champ);
        }
        $form['capacite'] = str_replace(' ', '', self::texte($request, 'capacite'));
        foreach (self::CASES as $champ) {
            $form[$champ] = $request->request->getBoolean($champ);
        }

        return $form;
    }

    /**
     * @param array<string, string|bool> $form
     *
     * @return list<string>
     */
    private function controler(array $form): array
    {
        $erreurs = [];
        if ('' === $form['titre'] || mb_strlen($form['titre']) > 120) {
            $erreurs[] = 'Le titre est obligatoire (120 caractères au plus).';
        }
        if (null === $this->em->find(Lieu::class, (int) $form['lieu'])) {
            $erreurs[] = 'Choisissez un lieu.';
        }

        $debut = DateFr::saisie($form['debut']);
        $fin = DateFr::saisie($form['fin']);
        if (null === $debut) {
            $erreurs[] = 'La date et l\'heure de début sont obligatoires.';
        }
        if ('' !== $form['fin'] && null === $fin) {
            $erreurs[] = 'La date de fin n\'est pas valide.';
        } elseif (null !== $debut && null !== $fin && $fin <= $debut) {
            $erreurs[] = 'La fin doit être après le début.';
        }
        foreach (['portes' => 'd\'accueil', 'fermeture' => 'de fermeture des portes'] as $champ => $nom) {
            if ('' !== $form[$champ] && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $form[$champ])) {
                $erreurs[] = sprintf('L\'heure %s n\'est pas valide.', $nom);
            }
        }

        if ('' !== $form['event'] && '' === LiveSettings::normalizeUuid($form['event'])) {
            $erreurs[] = 'L\'identifiant de l\'événement Tick Africa doit être un UUID (ex. 9a8b7c6d-5e4f-3a2b-1c0d-9e8f7a6b5c4d).';
        }
        if ('' !== $form['tarifs'] && '' === $form['event']) {
            $erreurs[] = 'Indiquez l\'événement Tick Africa avant de restreindre les billets.';
        }
        foreach (array_filter(array_map('trim', explode(',', $form['tarifs']))) as $tarif) {
            if ('' === LiveSettings::normalizeUuid($tarif)) {
                $erreurs[] = sprintf('« %s » n\'est pas un identifiant de billet (UUID).', $tarif);
            }
        }
        if (null !== $erreur = self::erreurCapacite($form['capacite'])) {
            $erreurs[] = $erreur;
        }

        return $erreurs;
    }

    /** @param array<string, string|bool> $form déjà contrôlé */
    private function appliquer(Evenement $e, array $form): void
    {
        foreach (self::CASES as $champ) {
            $e->$champ = $form[$champ];
        }
        $e->titre = $form['titre'];
        $e->lieu = $this->em->find(Lieu::class, (int) $form['lieu']);
        $e->setDebut(DateFr::saisie($form['debut']));
        $e->setFin(DateFr::saisie($form['fin']));
        $e->portes = str_replace(':', 'h', $form['portes']);
        $e->fermeture = str_replace(':', 'h', $form['fermeture']);
        $e->relier($form['event'].':'.$form['tarifs']);
        $e->capacite = '' === $form['capacite'] ? null : (int) $form['capacite'];
    }

    /** « majestic-marina-2026-12-06 », suivi d'un numéro si la clé est déjà prise. */
    private function cleLibre(Evenement $e): string
    {
        $base = mb_substr((new AsciiSlugger('fr'))->slug((string) $e->lieu?->nom)->lower()->toString(), 0, 45) ?: 'seance';
        $base .= '-'.$e->debut()->format('Y-m-d');
        $cle = $base;
        for ($n = 2; null !== $this->em->getRepository(Evenement::class)->findOneBy(['cle' => $cle]); ++$n) {
            $cle = $base.'-'.$n;
        }

        return $cle;
    }

    private function evenement(int $id): Evenement
    {
        return $this->em->find(Evenement::class, $id) ?? throw $this->createNotFoundException();
    }
}

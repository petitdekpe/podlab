<?php

namespace App\Controller;

use App\Entity\Direct;
use App\Entity\Evenement;
use App\Live\LiveSettings;
use App\Live\TickAfrica;
use App\Programme\Programme;
use App\Repository\LiveCodeRepository;
use App\Site\DateFr;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des directs : ajout, configuration, ouverture de l'accès, masquage, suppression.
 */
#[Route('/admin/directs')]
final class AdminDirectController extends AbstractController
{
    use AdminPageTrait;

    private const CASES = ['accesOuvert', 'filigrane', 'publie', 'habillage', 'enregistree'];
    /** Champs des heures de passage de l'habillage */
    private const HEURES = ['passage1', 'passage2', 'passage3'];
    private const HEURE = '/^([01]\d|2[0-3]):[0-5]\d$/D';

    public function __construct(
        private readonly Programme $programme,
        private readonly TickAfrica $tickAfrica,
        private readonly LiveCodeRepository $codes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_admin_directs', methods: ['GET'])]
    public function index(): Response
    {
        $now = new \DateTimeImmutable();

        return $this->page($this->render('admin/directs/index.html.twig', [
            'actuel' => $this->programme->directActuel($now),
            'lignes' => array_map(fn (Direct $d): array => [
                'd' => $d,
                'phase' => $d->phase($now),
                'stats' => $this->codes->stats($now->modify('-90 seconds'), $d),
            ], array_reverse($this->programme->tousDirects())),
        ]));
    }

    /** Nouveau direct, pour une séance (?evenement=) ou non. */
    #[Route('/nouveau', name: 'app_admin_direct_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $evenement = $this->em->find(Evenement::class, $request->query->getInt('evenement'));
        $debut = $evenement?->debut() ?? new \DateTimeImmutable('+7 days 13:00', DateFr::zone());
        $direct = new Direct($debut->modify('-30 minutes'), ($evenement?->fin() ?? $debut->modify('+2 hours'))->modify('+1 hour'));
        $direct->evenement = $evenement;
        $direct->titre = $evenement?->titre ?? $direct->titre;

        return $this->formulaire($request, $direct);
    }

    #[Route('/{id}', name: 'app_admin_direct', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(int $id, Request $request): Response
    {
        return $this->formulaire($request, $this->direct($id));
    }

    /** Ouvre ou ferme l'accès à tous les billets d'un coup. */
    #[Route('/{id}/acces', name: 'app_admin_direct_acces', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function acces(int $id, Request $request): Response
    {
        $direct = $this->direct($id);
        if ($this->csrfOk($request)) {
            $direct->accesOuvert = !$direct->accesOuvert;
            $this->em->flush();
            $this->addFlash('admin_ok', $direct->accesOuvert ? 'L\'accès au direct est ouvert.' : 'L\'accès au direct est fermé à tous les billets.');
        }

        return $this->redirectToRoute('app_admin_directs');
    }

    #[Route('/{id}/publier', name: 'app_admin_direct_publier', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publier(int $id, Request $request): Response
    {
        $direct = $this->direct($id);
        if ($this->csrfOk($request)) {
            $conflit = $direct->publie ? null : $this->conflit($direct, $direct->ouverture(), $direct->fin());
            if (null !== $conflit) {
                $this->addFlash('admin_error', $conflit);
            } else {
                $direct->publie = !$direct->publie;
                $this->em->flush();
                $this->addFlash('admin_ok', $direct->publie ? 'Le direct est affiché sur le site.' : 'Le direct n\'est plus affiché sur le site.');
            }
        }

        return $this->redirectToRoute('app_admin_directs');
    }

    #[Route('/{id}/supprimer', name: 'app_admin_direct_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(int $id, Request $request): Response
    {
        $direct = $this->direct($id);
        if ($this->csrfOk($request)) {
            if ($this->codes->countFor($direct) > 0) {
                $this->addFlash('admin_error', 'Des billets ont été validés pour ce direct : il ne peut plus être supprimé. Masquez-le.');
            } else {
                $this->em->remove($direct);
                $this->em->flush();
                $this->addFlash('admin_ok', 'Direct supprimé.');
            }
        }

        return $this->redirectToRoute('app_admin_directs');
    }

    private function formulaire(Request $request, Direct $direct): Response
    {
        $erreurs = [];
        $form = [
            'titre' => $direct->titre,
            'youtube' => $direct->youtube,
            'enregistree' => $direct->enregistree,
            'lancement' => $direct->lancementHeure,
            'ouverture' => $direct->ouverture()->format('Y-m-d\TH:i'),
            'fin' => $direct->fin()->format('Y-m-d\TH:i'),
            'evenement' => (string) $direct->evenement?->getId(),
            'event' => $direct->tickafricaEventId,
            'billetterie' => $direct->billetterieUrl,
            'accesOuvert' => $direct->accesOuvert,
            'filigrane' => $direct->filigrane,
            'publie' => $direct->publie,
            'habillage' => $direct->habillage,
            'duree' => (string) $direct->habillageDuree,
        ] + array_combine(self::HEURES, array_pad(explode(',', $direct->habillageHeures), Direct::PASSAGES, ''));

        if ($request->isMethod('POST')) {
            foreach (['titre', 'youtube', 'ouverture', 'fin', 'evenement', 'event', 'billetterie', 'lancement', 'duree', ...self::HEURES] as $champ) {
                $form[$champ] = self::texte($request, $champ);
            }
            foreach (self::CASES as $champ) {
                $form[$champ] = $request->request->getBoolean($champ);
            }
            $erreurs = $this->csrfOk($request) ? $this->controler($direct, $form) : ['Session expirée. Réessayez.'];

            if ([] === $erreurs) {
                $nouveau = null === $direct->getId();
                $direct->titre = $form['titre'] ?: LiveSettings::DEFAULT_TITLE;
                $direct->youtube = LiveSettings::extractVideoId($form['youtube']);
                $direct->lancementHeure = $form['enregistree'] ? $form['lancement'] : '';
                $direct->setFenetre(DateFr::saisie($form['ouverture']), DateFr::saisie($form['fin']));
                $direct->evenement = $this->em->find(Evenement::class, (int) $form['evenement']);
                $direct->tickafricaEventId = LiveSettings::normalizeUuid($form['event']);
                $direct->billetterieUrl = $form['billetterie'];
                $direct->habillageHeures = implode(',', self::heures($form));
                $direct->habillageDuree = (int) $form['duree'];
                foreach (self::CASES as $champ) {
                    $direct->$champ = $form[$champ];
                }
                $this->em->persist($direct);
                $this->em->flush();
                $this->addFlash('admin_ok', $nouveau ? 'Direct ajouté.' : 'Direct enregistré.');

                return $this->redirectToRoute('app_admin_directs');
            }
        }

        return $this->page($this->render('admin/directs/form.html.twig', [
            'direct' => $direct,
            'form' => $form,
            'erreurs' => $erreurs,
            'evenements' => array_reverse($this->programme->tousEvenements()),
            'tickafrica_events' => $this->tickAfrica->eventChoices(),
        ], new Response(status: [] === $erreurs ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    /**
     * @param array<string, string|bool> $form
     *
     * @return list<string>
     */
    private function controler(Direct $direct, array $form): array
    {
        $erreurs = [];
        if (mb_strlen($form['titre']) > 120) {
            $erreurs[] = 'Le titre ne doit pas dépasser 120 caractères.';
        }
        if ('' !== $form['youtube'] && '' === LiveSettings::extractVideoId($form['youtube'])) {
            $erreurs[] = 'Le lien ou l\'identifiant YouTube n\'est pas reconnu (attendu : lien youtube.com/watch?v=…, youtu.be/… ou identifiant de 11 caractères).';
        }
        if ('' !== $form['billetterie'] && ('' === LiveSettings::normalizeEmbedUrl($form['billetterie']) || mb_strlen($form['billetterie']) > 500)) {
            $erreurs[] = 'Le lien de la billetterie du direct doit être une adresse complète commençant par https:// (500 caractères au plus).';
        }
        if ('' !== $form['event'] && '' === LiveSettings::normalizeUuid($form['event'])) {
            $erreurs[] = 'L\'identifiant de l\'événement Tick Africa doit être un UUID (ex. 9a8b7c6d-5e4f-3a2b-1c0d-9e8f7a6b5c4d).';
        }
        if ('' !== $form['evenement'] && null === $this->em->find(Evenement::class, (int) $form['evenement'])) {
            $erreurs[] = 'Cette séance n\'existe plus : choisissez-en une autre.';
        }

        $duree = filter_var($form['duree'], \FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 300]]);
        if (false === $duree) {
            $erreurs[] = 'La durée d\'un passage de l\'habillage doit être comprise entre 10 et 300 secondes.';
        }

        $ouverture = DateFr::saisie($form['ouverture']);
        $fin = DateFr::saisie($form['fin']);
        if (null === $ouverture || null === $fin) {
            $erreurs[] = 'Les dates d\'ouverture et de fin sont obligatoires.';
        } elseif ($fin <= $ouverture) {
            $erreurs[] = 'La fin du direct doit être après son ouverture.';
        } elseif ($form['publie'] && null !== $conflit = $this->conflit($direct, $ouverture, $fin)) {
            $erreurs[] = $conflit;
        }

        $fenetreValide = null !== $ouverture && null !== $fin && $fin > $ouverture;
        // Heure à partir de laquelle les billets voient la vidéo
        $depart = $ouverture;
        if ($form['enregistree'] && '' !== $form['lancement']) {
            if (!preg_match(self::HEURE, $form['lancement'])) {
                $erreurs[] = 'L\'heure de lancement de la vidéo n\'est pas valide.';
            } elseif ($fenetreValide) {
                $depart = (new Direct($ouverture, $fin))->aLHeure($form['lancement']);
                if ($depart >= $fin) {
                    $erreurs[] = 'Le lancement de la vidéo doit tomber entre l\'ouverture et la fin du direct.';
                }
            }
        }

        $heures = self::heures($form);
        if ([] === $heures) {
            return $erreurs;
        }
        if (Direct::PASSAGES !== \count(preg_grep(self::HEURE, $heures))) {
            $erreurs[] = 'Habillage : saisissez les trois heures de passage, ou laissez les trois vides.';
        } elseif (false !== $duree && $fenetreValide) {
            $essai = new Direct($ouverture, $fin);
            $passages = array_map($essai->aLHeure(...), $heures);
            sort($passages);
            if ($passages[0] < $depart || end($passages) >= $fin) {
                $erreurs[] = 'Habillage : chaque passage doit tomber entre l\'ouverture (ou le lancement de la vidéo) et la fin du direct.';
            } elseif ($passages[1]->getTimestamp() - $passages[0]->getTimestamp() < $duree
                || $passages[2]->getTimestamp() - $passages[1]->getTimestamp() < $duree) {
                $erreurs[] = 'Habillage : deux passages se chevauchent, espacez-les.';
            }
        }

        return $erreurs;
    }

    /**
     * @param array<string, string|bool> $form
     *
     * @return list<string> les trois champs, ou [] s'ils sont tous vides
     */
    private static function heures(array $form): array
    {
        $heures = array_map(fn (string $champ): string => $form[$champ], self::HEURES);

        return '' === implode('', $heures) ? [] : $heures;
    }

    /** La page /live ne présente qu'un direct à la fois : deux directs publiés ne se chevauchent pas. */
    private function conflit(Direct $direct, \DateTimeImmutable $ouverture, \DateTimeImmutable $fin): ?string
    {
        $essai = new Direct($ouverture, $fin);
        foreach ($this->programme->tousDirects() as $autre) {
            if ($autre !== $direct && $autre->publie && $essai->chevauche($autre)) {
                return sprintf('Ce direct chevauche « %s » (du %s au %s) : un seul direct à la fois.', $autre->titre, $autre->ouverture()->format('d/m/Y H\hi'), $autre->fin()->format('d/m/Y H\hi'));
            }
        }

        return null;
    }

    private function direct(int $id): Direct
    {
        return $this->em->find(Direct::class, $id) ?? throw $this->createNotFoundException();
    }
}

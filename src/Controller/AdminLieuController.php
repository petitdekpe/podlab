<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\Lieu;
use App\Live\LiveSettings;
use App\Programme\Programme;
use App\Site\PhotosLieux;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des lieux et de leur photo, et des textes de la section « Les salles » de l'accueil.
 */
#[Route('/admin/lieux')]
final class AdminLieuController extends AbstractController
{
    use AdminPageTrait;

    /** Champs de texte et longueur maximale */
    private const TEXTES = ['nom' => 120, 'detail' => 160, 'adresse' => 200, 'maps' => 255, 'photoAlt' => 200];
    private const TEXTES_SECTION = ['lieux_titre' => 120, 'lieux_phrase' => 300];

    public function __construct(
        private readonly Programme $programme,
        private readonly PhotosLieux $photos,
        private readonly LiveSettings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_admin_lieux', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page($this->render('admin/lieux/index.html.twig', [
            'lignes' => array_map(fn (Lieu $l): array => ['l' => $l, 'evenements' => $this->nombreEvenements($l)], $this->programme->lieux()),
            'section' => ['lieux_titre' => $this->settings->lieuxTitre(), 'lieux_phrase' => $this->settings->lieuxPhrase()],
        ]));
    }

    /** Titre et phrase de la section « Les salles » de l'accueil. */
    #[Route('/section', name: 'app_admin_lieux_section', methods: ['POST'])]
    public function section(Request $request): Response
    {
        if ($this->csrfOk($request)) {
            foreach (self::TEXTES_SECTION as $champ => $max) {
                $this->settings->set($champ, mb_substr(self::texte($request, $champ), 0, $max));
            }
            $this->addFlash('admin_ok', 'Textes enregistrés.');
        }

        return $this->redirectToRoute('app_admin_lieux');
    }

    #[Route('/nouveau', name: 'app_admin_lieu_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        return $this->formulaire($request, new Lieu());
    }

    #[Route('/{id}', name: 'app_admin_lieu', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(int $id, Request $request): Response
    {
        return $this->formulaire($request, $this->lieu($id));
    }

    #[Route('/{id}/supprimer', name: 'app_admin_lieu_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(int $id, Request $request): Response
    {
        $lieu = $this->lieu($id);
        if ($this->csrfOk($request)) {
            if ($this->nombreEvenements($lieu) > 0) {
                $this->addFlash('admin_error', 'Des évènements se tiennent dans ce lieu : il ne peut pas être supprimé.');
            } else {
                $photo = $lieu->photo;
                $this->em->remove($lieu);
                $this->em->flush();
                $this->photos->supprimer($photo);
                $this->addFlash('admin_ok', 'Lieu supprimé.');
            }
        }

        return $this->redirectToRoute('app_admin_lieux');
    }

    private function formulaire(Request $request, Lieu $lieu): Response
    {
        $erreurs = [];
        $form = ['capacite' => (string) $lieu->capacite, 'cadrageX' => $lieu->cadrageX, 'cadrageY' => $lieu->cadrageY, 'retirer' => false];
        foreach (array_keys(self::TEXTES) as $champ) {
            $form[$champ] = $lieu->$champ;
        }

        if ($request->isMethod('POST')) {
            if (0 === $request->request->count() && (int) $request->server->get('CONTENT_LENGTH') > 0) {
                // Au-delà de post_max_size, PHP vide tout le formulaire
                $erreurs[] = 'Photo trop lourde pour le serveur. Réduisez-la puis réessayez.';
            } else {
                foreach (array_keys(self::TEXTES) as $champ) {
                    $form[$champ] = self::texte($request, $champ);
                }
                $form['capacite'] = str_replace(' ', '', self::texte($request, 'capacite'));
                foreach (['cadrageX', 'cadrageY'] as $champ) {
                    $form[$champ] = max(0, min(100, (int) self::texte($request, $champ)));
                }
                $form['retirer'] = $request->request->getBoolean('retirer');
                $erreurs = $this->csrfOk($request) ? $this->controler($lieu, $form) : ['Session expirée. Réessayez.'];
            }

            // La photo n'est enregistrée que si tout le reste est valable
            $nouvellePhoto = null;
            $envoi = $request->files->get('photo');
            if ([] === $erreurs && $envoi instanceof UploadedFile) {
                [$nouvellePhoto, $erreur] = $this->photos->enregistrer($envoi);
                if (null !== $erreur) {
                    $erreurs[] = $erreur;
                }
            }

            if ([] === $erreurs) {
                $nouveau = null === $lieu->getId();
                $ancienne = $lieu->photo;
                foreach (array_keys(self::TEXTES) as $champ) {
                    $lieu->$champ = $form[$champ];
                }
                $lieu->capacite = '' === $form['capacite'] ? null : (int) $form['capacite'];
                $lieu->cadrageX = $form['cadrageX'];
                $lieu->cadrageY = $form['cadrageY'];
                $lieu->photo = $nouvellePhoto ?? ($form['retirer'] ? '' : $ancienne);
                $this->em->persist($lieu);
                try {
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->photos->supprimer((string) $nouvellePhoto);

                    throw $e;
                }
                if ($lieu->photo !== $ancienne) {
                    $this->photos->supprimer($ancienne);
                }
                $this->addFlash('admin_ok', $nouveau ? 'Lieu ajouté.' : 'Lieu enregistré.');

                return $this->redirectToRoute('app_admin_lieux');
            }
        }

        return $this->page($this->render('admin/lieux/form.html.twig', [
            'lieu' => $lieu,
            'form' => $form,
            'erreurs' => $erreurs,
        ], new Response(status: [] === $erreurs ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    /**
     * @param array<string, string|int|bool> $form
     *
     * @return list<string>
     */
    private function controler(Lieu $lieu, array $form): array
    {
        $erreurs = [];
        if ('' === $form['nom']) {
            $erreurs[] = 'Le nom du lieu est obligatoire.';
        }
        foreach (self::TEXTES as $champ => $max) {
            if (mb_strlen($form[$champ]) > $max) {
                $erreurs[] = sprintf('« %s… » est trop long (%d caractères au plus).', mb_substr($form[$champ], 0, 30), $max);
            }
        }
        $homonyme = '' === $form['nom'] ? null : $this->em->getRepository(Lieu::class)->findOneBy(['nom' => $form['nom']]);
        if (null !== $homonyme && $homonyme !== $lieu) {
            $erreurs[] = 'Un autre lieu porte déjà ce nom.';
        }
        if (null !== $erreur = self::erreurCapacite($form['capacite'])) {
            $erreurs[] = $erreur;
        }

        return $erreurs;
    }

    private function nombreEvenements(Lieu $lieu): int
    {
        return $this->em->getRepository(Evenement::class)->count(['lieu' => $lieu]);
    }

    private function lieu(int $id): Lieu
    {
        return $this->em->find(Lieu::class, $id) ?? throw $this->createNotFoundException();
    }
}

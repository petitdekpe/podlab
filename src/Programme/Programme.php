<?php

namespace App\Programme;

use App\Entity\Direct;
use App\Entity\Evenement;
use App\Entity\Lieu;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Évènements, lieux et directs du site, gérés dans /admin.
 *
 * Si la base ne répond pas, les évènements viennent du dernier relevé connu, ou à
 * défaut du programme d'origine (aussi utilisé tant que la migration n'est pas
 * passée) : la billetterie reste ouverte. Les directs, eux, exigent la base.
 */
final class Programme implements ResetInterface
{
    private const CACHE_KEY = 'programme.evenements.v2';
    /** Après sa fin, un direct reste à l'affiche de /live (« la diffusion est terminée ») */
    private const APRES_DIRECT = '+1 hour';

    /** @var list<Evenement>|null */
    private ?array $evenements = null;
    /** @var list<Direct>|null */
    private ?array $directs = null;
    private bool $horsBase = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $marina22 = '',
        private readonly string $marina28 = '',
        private readonly string $wologuede28 = '',
    ) {
    }

    /**
     * Évènements affichés sur le site, dans l'ordre chronologique.
     *
     * @return list<Evenement>
     */
    public function evenements(): array
    {
        return array_values(array_filter($this->tousEvenements(), static fn (Evenement $e): bool => $e->publie));
    }

    /** @return list<Evenement> y compris masqués */
    public function tousEvenements(): array
    {
        if (null !== $this->evenements) {
            return $this->evenements;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        try {
            $evenements = array_values($this->em->getRepository(Evenement::class)->findBy([], ['debut' => 'ASC', 'id' => 'ASC']));
            $this->horsBase = false;
            $releve = serialize(array_map(static fn (Evenement $e): Evenement => $e->releve(), $evenements));
            if (!$item->isHit() || $item->get() !== $releve) {
                $this->cache->save($item->set($releve));
            }
        } catch (\Throwable $e) {
            $this->horsBase = true;
            $this->logger->error('Programme : base illisible, dernier relevé utilisé ({message}).', ['message' => $e->getMessage()]);
            $evenements = $item->isHit() ? unserialize($item->get(), ['allowed_classes' => [Evenement::class, Lieu::class, \DateTimeImmutable::class]]) : $this->origine();
        }

        return $this->evenements = $evenements;
    }

    /** @return list<Lieu> par ordre alphabétique */
    public function lieux(): array
    {
        return array_values($this->em->getRepository(Lieu::class)->findBy([], ['nom' => 'ASC']));
    }

    /**
     * Tous les directs, y compris masqués, dans l'ordre chronologique.
     *
     * @return list<Direct>
     */
    public function tousDirects(): array
    {
        return $this->directs ??= array_values($this->em->createQueryBuilder()
            ->select('d', 'e')->from(Direct::class, 'd')->leftJoin('d.evenement', 'e')
            ->orderBy('d.ouverture', 'ASC')->addOrderBy('d.id', 'ASC')
            ->getQuery()->getResult());
    }

    /**
     * Directs publiés et pas encore terminés : ceux que l'accueil propose. Vide si la base ne répond pas.
     *
     * @return list<Direct>
     */
    public function directsAVenir(?\DateTimeImmutable $now = null): array
    {
        try {
            $directs = $this->tousDirects();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter($directs, static fn (Direct $d): bool => $d->publie && Direct::TERMINE !== $d->phase($now)));
    }

    /**
     * Direct de la page /live : celui en cours, sinon celui qui vient de finir (une
     * heure), sinon le prochain, sinon le dernier. Erreur si la base ne répond pas.
     */
    public function directActuel(?\DateTimeImmutable $now = null): ?Direct
    {
        $now ??= new \DateTimeImmutable();
        $prochain = $dernier = null;

        foreach ($this->tousDirects() as $direct) {
            if (!$direct->publie) {
                continue;
            }
            $phase = $direct->phase($now);
            if (Direct::EN_COURS === $phase) {
                return $direct;
            }
            if (Direct::AVANT === $phase) {
                $prochain ??= $direct;
            } elseif (null === $dernier || $direct->fin() > $dernier->fin()) {
                $dernier = $direct;
            }
        }

        if (null !== $dernier && $now < $dernier->fin()->modify(self::APRES_DIRECT)) {
            return $dernier;
        }

        return $prochain ?? $dernier;
    }

    /** Les évènements affichés ne viennent pas de la base (injoignable, ou migration à passer). */
    public function horsBase(): bool
    {
        $this->tousEvenements();

        return $this->horsBase;
    }

    /** Entre deux requêtes d'un même processus. */
    public function reset(): void
    {
        $this->evenements = $this->directs = null;
    }

    /** @return list<Evenement> */
    private function origine(): array
    {
        $marina = new Lieu('Majestic Marina');
        [$marina->detail, $marina->adresse, $marina->maps] = ['Sofitel Cotonou Marina Hotel', 'Boulevard de la Marina, Cotonou', 'Sofitel Cotonou Marina Hotel, Boulevard de la Marina, Cotonou'];
        [$marina->photo, $marina->photoAlt] = ['images/salle-majestic-marina.webp', 'Fauteuils en velours rouge avec tablettes, salle du Majestic Marina'];
        [$marina->cadrageX, $marina->cadrageY] = [30, 70];
        $wologuede = new Lieu('Majestic Wologuèdè');
        [$wologuede->detail, $wologuede->adresse, $wologuede->maps] = ['Majestic Cinéma', 'Wologuèdè, Cotonou', 'Majestic Cinéma Wologuèdè, Cotonou'];

        $evenements = [];
        foreach ([
            ['marina-22', '2026-11-22', $this->marina22, true],
            ['marina-28', '2026-11-29', $this->marina28, false],
            ['wologuede-28', '2026-11-29', $this->wologuede28, false],
        ] as [$cle, $jour, $lien, $complet]) {
            $e = new Evenement($cle, new \DateTimeImmutable($jour.'T13:00:00+01:00'));
            $e->setFin(new \DateTimeImmutable($jour.'T15:00:00+01:00'));
            $e->portes = '12h30';
            $e->fermeture = '12h55';
            $e->lieu = str_starts_with($cle, 'marina') ? $marina : $wologuede;
            $e->relier($lien);
            $e->complet = $complet;
            $e->bilan = !$complet;
            $evenements[] = $e;
        }

        return $evenements;
    }
}

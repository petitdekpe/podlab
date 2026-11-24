<?php

namespace App\Billetterie;

use App\Live\LiveSettings;
use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Bilan des ventes des séances du 29 novembre, pour la page /ventes.
 *
 * Deux notions à ne pas confondre, chacune avec son pourcentage :
 * - tickets MIS EN VENTE (stock chez Tick Africa) : ventes = vendus ÷ mis en vente ;
 * - CAPACITÉ de la salle (saisie dans /admin) : remplissage = vendus ÷ capacité.
 * L'une ne remplace jamais l'autre : une valeur inconnue reste inconnue.
 *
 * Sources : GET /events/{id} (events:read) pour le stock de chaque billet,
 * GET /orders (orders:read) pour les commandes payées. Vendus = champ « vendu »
 * du billet, sinon total − restants, sinon billets des commandes payées. Mis en
 * vente = total du billet, sinon vendus + restants. Bilan gardé 60 s en cache.
 */
final class Ventes
{
    /** Les deux dernières séances : dimanche 29 novembre */
    public const SEANCES = ['marina-28', 'wologuede-28'];

    public const TIMEZONE = 'Africa/Porto-Novo';
    private const CACHE_KEY = 'ventes.rapport.v2';
    private const TTL = 60;
    private const PER_PAGE = 100;
    private const MAX_PAGES = 50;

    private const PAYEES = ['validated', 'paid', 'completed', 'complete', 'confirmed', 'success', 'succeeded', 'approved'];

    private const CHAMPS_VENDUS = ['sold_quantity', 'quantity_sold', 'sold', 'sold_count', 'tickets_sold', 'sales_count'];
    /** Total mis en vente. Ni « stock » (souvent le restant) ni « max_quantity » (souvent le maximum par commande). */
    private const CHAMPS_MIS_EN_VENTE = ['total_quantity', 'quantity_total', 'initial_quantity', 'capacity', 'quantity'];

    public function __construct(
        private readonly TickAfrica $tickAfrica,
        private readonly Billetterie $billetterie,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly LiveSettings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function rapport(bool $actualiser = false): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if (!$actualiser && $item->isHit()) {
            return $item->get();
        }

        $rapport = $this->calculer(new \DateTimeImmutable());
        $this->cache->save($item->set($rapport)->expiresAfter(self::TTL));

        return $rapport;
    }

    /**
     * @return array<string, mixed>
     */
    public function calculer(\DateTimeImmutable $now): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $seances = $this->billetterie->seances();
        $liens = $this->billetterie->links();
        $configure = $this->tickAfrica->isConfigured();

        // 1. Billets de chaque séance (GET /events/{id})
        $lignes = [];
        $champsProduit = [];
        foreach (self::SEANCES as $key) {
            $seance = $seances[$key] ?? null;
            [$eventId, $productIds] = Billetterie::parseLink($liens[$key] ?? '');
            $ligne = [
                'key' => $key,
                'salle' => $seance?->salle['name'] ?? $key,
                'starts_at' => $seance?->startsAt->format('c'),
                'event_id' => $eventId,
                'erreur' => null,
                'produits' => [],
            ];

            if (!$configure) {
                $ligne['erreur'] = 'Aucune clé API Tick Africa.';
            } elseif ('' === $eventId) {
                $ligne['erreur'] = 'Séance non reliée à un événement Tick Africa (/admin › Paramètres).';
            } else {
                try {
                    $event = $this->tickAfrica->event($eventId);
                    $ligne['produits'] = self::produits($event, $productIds);
                    foreach ($ligne['produits'] as $p) {
                        $champsProduit += array_flip(array_keys($p));
                    }
                } catch (TickAfricaUnavailable $e) {
                    $ligne['erreur'] = 404 === $e->getCode()
                        ? 'Tick Africa ne trouve pas l\'événement de cette séance.'
                        : 'Tick Africa ne répond pas (ou la clé n\'a pas la portée events:read).';
                }
            }
            $lignes[$key] = $ligne;
        }

        // 2. Commandes (GET /orders), une fois par événement
        $commandes = [];
        $commandesOk = $configure;
        $commandesErreur = $configure ? null : 'Aucune clé API Tick Africa.';
        $champsCommande = [];
        foreach (array_unique(array_filter(array_column($lignes, 'event_id'))) as $eventId) {
            try {
                foreach ($this->commandes($eventId) as $id => $commande) {
                    $commandes[$id] = $commande;
                    $champsCommande += array_flip(array_keys($commande));
                }
            } catch (TickAfricaUnavailable $e) {
                $commandesOk = false;
                $commandesErreur = 403 === $e->getCode()
                    ? 'La clé Tick Africa n\'a pas la portée orders:read : ventes récentes et recette réelle indisponibles.'
                    : 'Tick Africa n\'a pas renvoyé la liste des commandes.';
                $this->logger->warning('Ventes : commandes illisibles ({message}).', ['message' => $e->getMessage()]);
                break;
            }
        }
        if ($commandesOk && [] === $commandes && [] === array_filter(array_column($lignes, 'event_id'))) {
            $commandesOk = false;
        }

        // 3. Attribution des commandes aux séances
        $parSeance = array_fill_keys(self::SEANCES, []);
        $partageEvent = array_count_values(array_filter(array_column($lignes, 'event_id')));
        foreach ($commandes as $commande) {
            if (!\in_array(strtolower((string) ($commande['status'] ?? '')), self::PAYEES, true)) {
                continue;
            }
            $eventCommande = self::eventId($commande);
            $items = self::items($commande);
            $date = self::date($commande, $tz);

            foreach ($lignes as $key => $ligne) {
                if ('' !== $eventCommande && '' !== $ligne['event_id'] && $eventCommande !== $ligne['event_id']) {
                    continue;
                }
                $ids = array_column($ligne['produits'], 'id');
                $billets = 0;
                $recette = 0.0;
                $parTarif = [];
                $retenus = 0;
                $identifies = array_filter($items, static fn (array $i): bool => '' !== $i['product_id']);

                if ([] !== $identifies) {
                    foreach ($identifies as $i) {
                        if (\in_array($i['product_id'], $ids, true)) {
                            ++$retenus;
                            $billets += $i['quantity'];
                            $montant = $i['total'] ?? (null !== $i['unit_price'] ? $i['unit_price'] * $i['quantity'] : self::prix($ligne['produits'], $i['product_id']) * $i['quantity']);
                            $recette += $montant;
                            $parTarif[$i['product_id']] = ($parTarif[$i['product_id']] ?? 0) + $i['quantity'];
                        }
                    }
                } elseif (($commande['metadata']['seance'] ?? null) === $key
                    || ('' !== $eventCommande && 1 === ($partageEvent[$ligne['event_id']] ?? 0))) {
                    // Pas de détail des billets : toute la commande va à la séance
                    $billets = max(1, array_sum(array_column($items, 'quantity')) ?: (int) ($commande['quantity'] ?? $commande['tickets_count'] ?? 1));
                    $recette = self::montant($commande) ?? 0.0;
                }

                if (0 === $billets) {
                    continue;
                }
                // Lignes sans prix : montant de la commande, si elle ne porte que cette séance
                $total = self::montant($commande);
                if ($recette <= 0 && null !== $total && $retenus === \count($identifies)) {
                    $recette = $total;
                }
                $parSeance[$key][] = ['billets' => $billets, 'recette' => $recette, 'date' => $date, 'tarifs' => $parTarif];
            }
        }

        // 4. Chiffres par séance
        $resultat = [];
        foreach ($lignes as $key => $ligne) {
            $resultat[$key] = $this->chiffres($ligne, $parSeance[$key], $commandesOk, $now);
        }

        return [
            'at' => $now->format('c'),
            'seances' => $resultat,
            'global' => self::global($resultat),
            'commandes_ok' => $commandesOk,
            'commandes_erreur' => $commandesErreur,
            'configure' => $configure,
            'test' => $this->tickAfrica->isTestMode(),
            'champs' => [
                'produit' => array_keys($champsProduit),
                'commande' => array_keys($champsCommande),
            ],
        ];
    }

    /**
     * @param array<string, mixed>       $ligne
     * @param list<array<string, mixed>> $payees commandes payées de la séance
     *
     * @return array<string, mixed>
     */
    private function chiffres(array $ligne, array $payees, bool $commandesOk, \DateTimeImmutable $now): array
    {
        $billetsCommandes = array_sum(array_column($payees, 'billets'));
        $recetteCommandes = array_sum(array_column($payees, 'recette'));
        $parTarif = [];
        foreach ($payees as $v) {
            foreach ($v['tarifs'] as $id => $n) {
                $parTarif[$id] = ($parTarif[$id] ?? 0) + $n;
            }
        }
        // Commandes sans détail des billets : répartition entre tarifs impossible
        $detail = array_sum($parTarif) === $billetsCommandes;

        $tarifs = [];
        $source = null;
        foreach ($ligne['produits'] as $p) {
            $aVendre = isset($p['available_quantity']) && is_numeric($p['available_quantity']) ? max(0, (int) $p['available_quantity']) : null;
            $misEnVente = self::nombre($p, self::CHAMPS_MIS_EN_VENTE);
            if (null !== $misEnVente && null !== $aVendre && $misEnVente < $aVendre) {
                $misEnVente = null; // ce champ n'est pas le total mis en vente
            }
            $vendus = self::nombre($p, self::CHAMPS_VENDUS);
            $origine = 'billetterie';
            if (null === $vendus && null !== $misEnVente && null !== $aVendre) {
                $vendus = $misEnVente - $aVendre;
            }
            if (null === $vendus && $commandesOk) {
                $vendus = match (true) {
                    $detail => $parTarif[$p['id']] ?? 0,
                    1 === \count($ligne['produits']) => $billetsCommandes,
                    default => null,
                };
                $origine = 'commandes';
            }
            if (null !== $vendus) {
                $source ??= $origine;
            }

            $tarifs[] = [
                'vendus' => $vendus,
                'mis_en_vente' => $misEnVente ?? (null !== $vendus && null !== $aVendre ? $vendus + $aVendre : null),
                'valeur' => null !== $vendus ? $vendus * (int) round((float) ($p['price'] ?? 0)) : null,
            ];
        }

        $vendus = self::somme($tarifs, 'vendus');

        // Recette : celle des commandes si elles expliquent tous les tickets vendus,
        // sinon la valeur des tickets vendus au prix du billet
        $recetteReelle = $commandesOk && null !== $vendus && $billetsCommandes === $vendus && $recetteCommandes > 0;

        $h24 = 0;
        foreach ($payees as $v) {
            if (null !== $v['date'] && $v['date'] >= $now->modify('-24 hours')) {
                $h24 += $v['billets'];
            }
        }

        $debut = null !== $ligne['starts_at'] ? new \DateTimeImmutable($ligne['starts_at']) : null;

        return [
            'salle' => $ligne['salle'],
            'erreur' => $ligne['erreur'],
            'source' => $source,
            'recette' => $recetteReelle ? (int) round($recetteCommandes) : self::somme($tarifs, 'valeur'),
            'recette_reelle' => $recetteReelle,
            'h24' => $h24,
            'jours_restants' => null !== $debut && $debut > $now ? ($debut->getTimestamp() - $now->getTimestamp()) / 86400 : 0.0,
        ] + self::taux($vendus, self::somme($tarifs, 'mis_en_vente'), $this->capaciteSalle($ligne['key']));
    }

    /**
     * Les deux pourcentages de la page, calculés au même endroit pour chaque séance et le total.
     *
     * @return array{vendus: int|null, mis_en_vente: int|null, capacite: int|null, taux_ventes: float|null, taux_remplissage: float|null}
     */
    private static function taux(?int $vendus, ?int $misEnVente, ?int $capacite): array
    {
        return [
            'vendus' => $vendus,
            'mis_en_vente' => $misEnVente,
            'capacite' => $capacite,
            'taux_ventes' => null !== $vendus && $misEnVente > 0 ? $vendus / $misEnVente : null,
            'taux_remplissage' => null !== $vendus && $capacite > 0 ? $vendus / $capacite : null,
        ];
    }

    /**
     * Somme d'une colonne ; null dès qu'une valeur est inconnue (pas de total partiel).
     *
     * @param array<array<string, mixed>> $lignes
     */
    private static function somme(array $lignes, string $champ): ?int
    {
        $valeurs = array_column($lignes, $champ);

        return [] === $valeurs || \in_array(null, $valeurs, true) ? null : (int) array_sum($valeurs);
    }

    /** Capacité de la salle saisie dans /admin ; null si absente ou base injoignable. */
    private function capaciteSalle(string $seance): ?int
    {
        try {
            return $this->settings->seanceCapacity($seance);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $seances
     *
     * @return array<string, mixed>
     */
    private static function global(array $seances): array
    {
        return [
            'recette' => self::somme($seances, 'recette'),
            'recette_reelle' => !\in_array(false, array_column($seances, 'recette_reelle'), true),
            'h24' => (int) array_sum(array_column($seances, 'h24')),
            'jours_restants' => max([0.0, ...array_column($seances, 'jours_restants')]),
        ] + self::taux(self::somme($seances, 'vendus'), self::somme($seances, 'mis_en_vente'), self::somme($seances, 'capacite'));
    }

    /**
     * Toutes les commandes d'un événement, page après page.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws TickAfricaUnavailable
     */
    private function commandes(string $eventId): array
    {
        $commandes = [];
        $query = ['event_id' => $eventId, 'per_page' => self::PER_PAGE, 'page' => 1];

        for ($i = 0; $i < self::MAX_PAGES; ++$i) {
            try {
                $reponse = $this->tickAfrica->orders($query);
            } catch (TickAfricaUnavailable $e) {
                // Filtre event_id refusé : liste complète, triée ensuite par billet et événement
                if (!isset($query['event_id']) || \in_array($e->getCode(), [401, 403, 429], true)) {
                    throw $e;
                }
                unset($query['event_id']);
                $reponse = $this->tickAfrica->orders($query);
            }
            $lot = \is_array($reponse['data'] ?? null) ? array_values(array_filter($reponse['data'], 'is_array')) : [];
            $nouvelles = 0;
            foreach ($lot as $n => $commande) {
                $id = (string) ($commande['id'] ?? $commande['order_id'] ?? $commande['reference'] ?? $query['page'].'-'.$n);
                if (!isset($commandes[$id])) {
                    $commandes[$id] = $commande;
                    ++$nouvelles;
                }
            }

            $meta = \is_array($reponse['meta'] ?? null) ? $reponse['meta'] : [];
            $meta = \is_array($meta['pagination'] ?? null) ? $meta['pagination'] + $meta : $meta;
            $curseur = $meta['next_cursor'] ?? null;
            $suite = match (true) {
                0 === $nouvelles => false,
                isset($meta['last_page'], $meta['current_page']) => (int) $meta['current_page'] < (int) $meta['last_page'],
                isset($meta['total_pages'], $meta['current_page']) => (int) $meta['current_page'] < (int) $meta['total_pages'],
                \is_string($curseur) && '' !== $curseur => true,
                \array_key_exists('has_more', $meta) => (bool) $meta['has_more'],
                \is_array($reponse['links'] ?? null) && \array_key_exists('next', $reponse['links']) => null !== $reponse['links']['next'],
                default => \count($lot) >= self::PER_PAGE,
            };
            if (!$suite) {
                break;
            }
            if (\is_string($curseur) && '' !== $curseur) {
                $query['cursor'] = $curseur;
            } else {
                ++$query['page'];
            }
        }

        return $commandes;
    }

    /**
     * Billets d'un événement retenus pour une séance (mêmes règles que la billetterie).
     *
     * @param array<string, mixed> $event
     * @param list<string>         $productIds
     *
     * @return list<array<string, mixed>>
     */
    private static function produits(array $event, array $productIds): array
    {
        $produits = array_values(array_filter(\is_array($event['products'] ?? null) ? $event['products'] : [], static fn ($p): bool => \is_array($p) && '' !== (string) ($p['id'] ?? '')));
        if ([] !== $productIds) {
            $produits = array_filter($produits, static fn (array $p): bool => \in_array(strtolower((string) $p['id']), $productIds, true));
        } else {
            $places = array_filter($produits, static fn (array $p): bool => 'ticket' === ($p['type'] ?? 'ticket'));
            $produits = [] !== $places ? $places : $produits;
        }

        return array_map(static function (array $p): array {
            $p['id'] = strtolower((string) $p['id']);

            return $p;
        }, array_values($produits));
    }

    /** @param array<string, mixed> $commande */
    private static function eventId(array $commande): string
    {
        $id = $commande['event_id'] ?? (\is_array($commande['event'] ?? null) ? ($commande['event']['id'] ?? '') : '');

        return strtolower(trim((string) $id));
    }

    /**
     * @param array<string, mixed> $commande
     *
     * @return list<array{product_id: string, quantity: int, unit_price: float|null, total: float|null}>
     */
    private static function items(array $commande): array
    {
        $items = $commande['items'] ?? $commande['order_items'] ?? $commande['lines'] ?? [];
        if (!\is_array($items)) {
            return [];
        }

        $lignes = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $produit = $item['product_id'] ?? (\is_array($item['product'] ?? null) ? ($item['product']['id'] ?? '') : ($item['ticket_type_id'] ?? ''));
            $prix = $item['unit_price'] ?? $item['price'] ?? null;
            $total = $item['total'] ?? $item['amount'] ?? $item['subtotal'] ?? null;
            $lignes[] = [
                'product_id' => strtolower(trim((string) $produit)),
                'quantity' => max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1)),
                'unit_price' => is_numeric($prix) ? (float) $prix : null,
                'total' => is_numeric($total) ? (float) $total : null,
            ];
        }

        return $lignes;
    }

    /** @param array<string, mixed> $commande */
    private static function montant(array $commande): ?float
    {
        foreach (['amount', 'total_amount', 'total', 'amount_paid', 'paid_amount'] as $champ) {
            $valeur = $commande[$champ] ?? null;
            if (\is_array($valeur)) {
                $valeur = $valeur['amount'] ?? $valeur['value'] ?? null;
            }
            if (is_numeric($valeur)) {
                return (float) $valeur;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $commande */
    private static function date(array $commande, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        foreach (['paid_at', 'validated_at', 'completed_at', 'confirmed_at', 'created_at', 'date'] as $champ) {
            $valeur = $commande[$champ] ?? null;
            if (\is_string($valeur) && '' !== $valeur) {
                try {
                    return new \DateTimeImmutable($valeur, $tz);
                } catch (\Exception) {
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $produit
     * @param list<string>         $champs
     */
    private static function nombre(array $produit, array $champs): ?int
    {
        foreach ($champs as $champ) {
            if (isset($produit[$champ]) && is_numeric($produit[$champ])) {
                return max(0, (int) $produit[$champ]);
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $produits */
    private static function prix(array $produits, string $id): float
    {
        foreach ($produits as $p) {
            if ($p['id'] === $id) {
                return (float) ($p['price'] ?? 0);
            }
        }

        return 0.0;
    }
}

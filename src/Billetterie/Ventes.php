<?php

namespace App\Billetterie;

use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Bilan des ventes des séances du 29 novembre, pour la page /ventes.
 *
 * Deux sources Tick Africa :
 * - GET /events/{id} (events:read) : places restantes de chaque billet, et
 *   la capacité ou le nombre vendu si Tick Africa les fournit ;
 * - GET /orders (orders:read) : les commandes, pour le rythme des ventes,
 *   la recette, le panier moyen et les paiements non aboutis.
 *
 * Places vendues = champ « vendu » du billet, sinon capacité − restantes,
 * sinon billets des commandes payées. Places totales = capacité, sinon
 * vendues + restantes. Le bilan est gardé 60 s en cache.
 */
final class Ventes
{
    /** Les deux dernières séances : dimanche 29 novembre */
    public const SEANCES = ['marina-28', 'wologuede-28'];

    public const TIMEZONE = 'Africa/Porto-Novo';
    private const CACHE_KEY = 'ventes.rapport';
    private const TTL = 60;
    private const PER_PAGE = 100;
    private const MAX_PAGES = 50;
    private const JOURS_GRAPHIQUE = 14;
    private const SOURCE_SITE = 'podlab.beninbouge.com';

    private const PAYEES = ['validated', 'paid', 'completed', 'complete', 'confirmed', 'success', 'succeeded', 'approved'];
    private const EN_ATTENTE = ['pending', 'processing', 'awaiting_payment', 'created', 'initiated', 'in_progress'];

    private const CHAMPS_VENDUS = ['sold_quantity', 'quantity_sold', 'sold', 'sold_count', 'tickets_sold', 'sales_count'];
    private const CHAMPS_CAPACITE = ['total_quantity', 'quantity_total', 'initial_quantity', 'capacity', 'quantity', 'stock', 'max_quantity'];

    public function __construct(
        private readonly TickAfrica $tickAfrica,
        private readonly Billetterie $billetterie,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
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
                'sub' => $seance?->salle['sub'] ?? '',
                'jour' => $seance?->jour() ?? '',
                'heure' => $seance?->heure() ?? '',
                'starts_at' => $seance?->startsAt->format('c'),
                'event_id' => $eventId,
                'event_name' => '',
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
                    $ligne['event_name'] = (string) ($event['name'] ?? '');
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
                    ? 'La clé Tick Africa n\'a pas la portée orders:read : rythme des ventes et recette réelle indisponibles.'
                    : 'Tick Africa n\'a pas renvoyé la liste des commandes.';
                $this->logger->warning('Ventes : commandes illisibles ({message}).', ['message' => $e->getMessage()]);
                break;
            }
        }
        if ($commandesOk && [] === $commandes && [] === array_filter(array_column($lignes, 'event_id'))) {
            $commandesOk = false;
        }

        // 3. Attribution des commandes aux séances
        $parSeance = array_fill_keys(self::SEANCES, ['payees' => [], 'attente' => 0, 'annulees' => 0]);
        $partageEvent = array_count_values(array_filter(array_column($lignes, 'event_id')));
        foreach ($commandes as $commande) {
            $statut = strtolower((string) ($commande['status'] ?? ''));
            $eventCommande = self::eventId($commande);
            $items = self::items($commande);
            $source = (string) (($commande['metadata'] ?? [])['source'] ?? '');
            $date = self::date($commande, $statut, $tz);

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
                if (\in_array($statut, self::PAYEES, true)) {
                    // Lignes sans prix : montant de la commande, si elle ne porte que cette séance
                    $total = self::montant($commande);
                    if ($recette <= 0 && null !== $total && $retenus === \count($identifies)) {
                        $recette = $total;
                    }
                    $parSeance[$key]['payees'][] = [
                        'billets' => $billets,
                        'recette' => $recette,
                        'date' => $date?->format('c'),
                        'site' => self::SOURCE_SITE === $source,
                        'tarifs' => $parTarif,
                    ];
                } elseif (\in_array($statut, self::EN_ATTENTE, true)) {
                    ++$parSeance[$key]['attente'];
                } else {
                    ++$parSeance[$key]['annulees'];
                }
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
            'jours' => self::jours($resultat, $now, $tz),
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
     * @param array<string, mixed>                                                       $ligne
     * @param array{payees: list<array<string, mixed>>, attente: int, annulees: int}     $ventes
     *
     * @return array<string, mixed>
     */
    private function chiffres(array $ligne, array $ventes, bool $commandesOk, \DateTimeImmutable $now): array
    {
        $billetsCommandes = array_sum(array_column($ventes['payees'], 'billets'));
        $recetteCommandes = array_sum(array_column($ventes['payees'], 'recette'));
        $parTarif = [];
        foreach ($ventes['payees'] as $v) {
            foreach ($v['tarifs'] as $id => $n) {
                $parTarif[$id] = ($parTarif[$id] ?? 0) + $n;
            }
        }

        $tarifs = [];
        $source = null;
        foreach ($ligne['produits'] as $p) {
            $restantes = isset($p['available_quantity']) && is_numeric($p['available_quantity']) ? max(0, (int) $p['available_quantity']) : null;
            $capacite = self::nombre($p, self::CHAMPS_CAPACITE);
            $vendues = self::nombre($p, self::CHAMPS_VENDUS);
            $origine = null !== $vendues ? 'billetterie' : null;
            if (null === $vendues && null !== $capacite && null !== $restantes) {
                $vendues = max(0, $capacite - $restantes);
                $origine = 'billetterie';
            }
            if (null === $vendues && $commandesOk) {
                // Commandes sans détail des billets : on ne sait pas les répartir entre tarifs
                $vendues = [] === $parTarif && 1 === \count($ligne['produits']) ? $billetsCommandes : ($parTarif[(string) $p['id']] ?? 0);
                $origine = 'commandes';
            }
            $source ??= $origine;
            $prix = (int) round((float) ($p['price'] ?? 0));
            $total = $capacite ?? (null !== $vendues && null !== $restantes ? $vendues + $restantes : null);

            $tarifs[] = [
                'nom' => trim((string) ($p['name'] ?? '')) ?: 'Billet',
                'prix' => $prix,
                'restantes' => $restantes,
                'vendues' => $vendues,
                'total' => $total,
                'taux' => null !== $total && $total > 0 && null !== $vendues ? $vendues / $total : null,
                'valeur' => null !== $vendues ? $vendues * $prix : null,
                'en_vente' => (bool) ($p['is_available'] ?? false),
            ];
        }

        $somme = static fn (string $champ): ?int => [] === $tarifs || \in_array(null, array_column($tarifs, $champ), true) ? null : array_sum(array_column($tarifs, $champ));
        $vendues = $somme('vendues');
        $total = $somme('total');
        $restantes = $somme('restantes');
        $valeur = $somme('valeur');

        // Recette : celle des commandes si elles expliquent toutes les places vendues,
        // sinon la valeur des places vendues au prix du billet
        $recetteReelle = $commandesOk && null !== $vendues && $billetsCommandes === $vendues && $recetteCommandes > 0;
        $recette = $recetteReelle ? (int) round($recetteCommandes) : $valeur;

        $debut = null !== $ligne['starts_at'] ? new \DateTimeImmutable($ligne['starts_at']) : null;
        $joursRestants = null !== $debut && $debut > $now ? ($debut->getTimestamp() - $now->getTimestamp()) / 86400 : 0.0;

        // Rythme, d'après les dates des commandes payées
        $datees = array_values(array_filter($ventes['payees'], static fn (array $v): bool => null !== $v['date']));
        $depuis = static fn (string $intervalle): int => array_sum(array_map(
            static fn (array $v): int => new \DateTimeImmutable($v['date']) >= $now->modify($intervalle) ? $v['billets'] : 0,
            $datees,
        ));
        $h24 = $depuis('-24 hours');
        $j7 = $depuis('-7 days');
        $premiere = [] === $datees ? null : min(array_map(static fn (array $v): string => (new \DateTimeImmutable($v['date']))->format('c'), $datees));
        $joursDeVente = null !== $premiere ? max(1.0, ($now->getTimestamp() - (new \DateTimeImmutable($premiere))->getTimestamp()) / 86400) : null;
        // Moyenne quotidienne sur les 7 derniers jours (ou depuis la première vente)
        $parJour = null !== $joursDeVente ? $j7 / min(7.0, $joursDeVente) : null;

        $projection = null;
        if (null !== $parJour && null !== $vendues) {
            $projection = (int) round($vendues + $parJour * $joursRestants);
            if (null !== $total) {
                $projection = min($total, $projection);
            }
        }

        $nbPayees = \count($ventes['payees']);
        $tentatives = $nbPayees + $ventes['annulees'];

        return [
            'key' => $ligne['key'],
            'salle' => $ligne['salle'],
            'sub' => $ligne['sub'],
            'jour' => $ligne['jour'],
            'heure' => $ligne['heure'],
            'event_name' => $ligne['event_name'],
            'erreur' => $ligne['erreur'],
            'tarifs' => $tarifs,
            'source' => $source,
            'total' => $total,
            'vendues' => $vendues,
            'restantes' => $restantes,
            'illimite' => [] !== $tarifs && null === $restantes,
            'taux' => null !== $total && $total > 0 && null !== $vendues ? $vendues / $total : null,
            'recette' => $recette,
            'recette_reelle' => $recetteReelle,
            'commandes' => $nbPayees,
            'en_attente' => $ventes['attente'],
            'annulees' => $ventes['annulees'],
            'taux_paiement' => $tentatives > 0 ? $nbPayees / $tentatives : null,
            'panier' => $nbPayees > 0 ? $billetsCommandes / $nbPayees : null,
            'panier_recette' => $nbPayees > 0 ? $recetteCommandes / $nbPayees : null,
            'billets_commandes' => $billetsCommandes,
            'billets_site' => array_sum(array_map(static fn (array $v): int => $v['site'] ? $v['billets'] : 0, $ventes['payees'])),
            'h24' => $h24,
            'j7' => $j7,
            'par_jour' => $parJour,
            'jours_restants' => $joursRestants,
            'besoin_par_jour' => null !== $restantes && $joursRestants > 0 ? $restantes / max(1.0, $joursRestants) : null,
            'projection' => $projection,
            'premiere_vente' => $premiere,
            'ventes' => array_map(static fn (array $v): array => ['billets' => $v['billets'], 'date' => $v['date']], $datees),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $seances
     *
     * @return array<string, mixed>
     */
    private static function global(array $seances): array
    {
        $somme = static function (string $champ) use ($seances): ?int {
            $valeurs = array_column($seances, $champ);

            return \in_array(null, $valeurs, true) ? null : (int) array_sum($valeurs);
        };
        $total = $somme('total');
        $vendues = $somme('vendues');
        $commandes = (int) array_sum(array_column($seances, 'commandes'));
        $billetsCommandes = (int) array_sum(array_column($seances, 'billets_commandes'));
        $tentatives = $commandes + (int) array_sum(array_column($seances, 'annulees'));
        $parJour = array_column($seances, 'par_jour');

        return [
            'total' => $total,
            'vendues' => $vendues,
            'restantes' => $somme('restantes'),
            'taux' => null !== $total && $total > 0 && null !== $vendues ? $vendues / $total : null,
            'recette' => $somme('recette'),
            'recette_reelle' => [] !== $seances && !\in_array(false, array_column($seances, 'recette_reelle'), true),
            'commandes' => $commandes,
            'en_attente' => (int) array_sum(array_column($seances, 'en_attente')),
            'annulees' => (int) array_sum(array_column($seances, 'annulees')),
            'taux_paiement' => $tentatives > 0 ? $commandes / $tentatives : null,
            'panier' => $commandes > 0 ? $billetsCommandes / $commandes : null,
            'billets_commandes' => $billetsCommandes,
            'billets_site' => (int) array_sum(array_column($seances, 'billets_site')),
            'h24' => (int) array_sum(array_column($seances, 'h24')),
            'j7' => (int) array_sum(array_column($seances, 'j7')),
            'par_jour' => \in_array(null, $parJour, true) || [] === $parJour ? null : array_sum($parJour),
            'projection' => $somme('projection'),
            'jours_restants' => max([0.0, ...array_column($seances, 'jours_restants')]),
        ];
    }

    /**
     * Billets payés par jour (heure du Bénin), sur les 14 derniers jours au plus.
     *
     * @param array<string, array<string, mixed>> $seances
     *
     * @return list<array<string, mixed>>
     */
    private static function jours(array $seances, \DateTimeImmutable $now, \DateTimeZone $tz): array
    {
        $aujourdhui = $now->setTimezone($tz)->setTime(0, 0);
        $premieres = array_filter(array_column($seances, 'premiere_vente'));
        if ([] === $premieres) {
            return [];
        }
        $debut = (new \DateTimeImmutable(min($premieres)))->setTimezone($tz)->setTime(0, 0);
        $debut = max($debut, $aujourdhui->modify('-'.(self::JOURS_GRAPHIQUE - 1).' days'));

        $jours = [];
        for ($d = $debut; $d <= $aujourdhui; $d = $d->modify('+1 day')) {
            $jours[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'label' => self::jourCourt($d), 'total' => 0] + array_fill_keys(array_keys($seances), 0);
        }
        foreach ($seances as $key => $seance) {
            foreach ($seance['ventes'] as $v) {
                $jour = (new \DateTimeImmutable($v['date']))->setTimezone($tz)->format('Y-m-d');
                if (isset($jours[$jour])) {
                    $jours[$jour][$key] += $v['billets'];
                    $jours[$jour]['total'] += $v['billets'];
                }
            }
        }

        return array_values($jours);
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
    private static function date(array $commande, string $statut, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $champs = \in_array($statut, self::PAYEES, true)
            ? ['paid_at', 'validated_at', 'completed_at', 'confirmed_at', 'created_at', 'date']
            : ['created_at', 'date'];
        foreach ($champs as $champ) {
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

    private static function jourCourt(\DateTimeImmutable $d): string
    {
        $jours = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];

        return $jours[(int) $d->format('w')].' '.$d->format('j');
    }
}

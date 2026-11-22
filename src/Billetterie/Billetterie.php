<?php

namespace App\Billetterie;

use App\Live\LiveSettings;
use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Billetterie des séances en salle, vendue par l'API Organisateur de Tick Africa.
 *
 * Dates et salles sont fixées ici ; tarifs, prix et places restantes sont lus
 * chez Tick Africa (GET /events/{id}, gardé 30 s en cache). Si Tick Africa ne
 * répond pas, on affiche le dernier relevé connu plutôt qu'une page vide.
 *
 * Chaque séance est reliée à un événement Tick Africa par une variable
 * d'environnement : « uuid-evenement » ou « uuid-evenement:uuid-tarif,uuid-tarif »
 * pour ne retenir que certains billets (deux séances dans un même événement).
 */
final class Billetterie
{
    public const SALLES = [
        'marina' => [
            'name' => 'Majestic Marina',
            'sub' => 'Sofitel Cotonou Marina Hotel',
            'address' => 'Boulevard de la Marina, Cotonou',
            'maps' => 'Sofitel Cotonou Marina Hotel, Boulevard de la Marina, Cotonou',
        ],
        'wologuede' => [
            'name' => 'Majestic Wologuèdè',
            'sub' => 'Majestic Cinéma',
            'address' => 'Wologuèdè, Cotonou',
            'maps' => 'Majestic Cinéma Wologuèdè, Cotonou',
        ],
    ];

    private const FRESH_TTL = 30;
    private const RETRY_TTL = 10;
    private const LAST_GOOD_TTL = 86400 * 7;

    /** @var array<string, Seance>|null */
    private ?array $seances = null;

    public function __construct(
        private readonly TickAfrica $tickAfrica,
        private readonly LiveSettings $settings,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $marina22 = '',
        private readonly string $marina28 = '',
        private readonly string $wologuede28 = '',
    ) {
    }

    /**
     * Les séances, dans l'ordre chronologique, indexées par clé.
     *
     * @return array<string, Seance>
     */
    public function seances(): array
    {
        if (null !== $this->seances) {
            return $this->seances;
        }

        // Réglages de /admin : si la base ne répond pas, la séance du 22 reste complète
        // et les séances sont reliées aux événements de l'environnement
        $liens = $this->defaultLinks();
        try {
            $complet22 = $this->settings->soldOut();
            $finDirect = $this->settings->endsAt();
            foreach ($liens as $key => $defaut) {
                $liens[$key] = $this->settings->seanceLink($key) ?: $defaut;
            }
        } catch (\Throwable) {
            $complet22 = true;
            $finDirect = new \DateTimeImmutable('2026-11-22T16:00:00+01:00');
        }

        $plan = [
            ['marina-22', '2026-11-22T13:00:00+01:00', 'marina', $liens['marina-22'], $complet22, new \DateTimeImmutable() < $finDirect],
            ['marina-28', '2026-11-29T13:00:00+01:00', 'marina', $liens['marina-28'], false, false],
            ['wologuede-28', '2026-11-29T13:00:00+01:00', 'wologuede', $liens['wologuede-28'], false, false],
        ];

        $this->seances = [];
        foreach ($plan as [$key, $start, $salle, $link, $forcedSoldOut, $live]) {
            $this->seances[$key] = $this->build($key, new \DateTimeImmutable($start), $salle, $link, $forcedSoldOut, $live);
        }

        return $this->seances;
    }

    /**
     * Événements de l'environnement (TICKAFRICA_SEANCE_…), utilisés tant que rien n'est saisi dans /admin.
     *
     * @return array<string, string>
     */
    public function defaultLinks(): array
    {
        return ['marina-22' => $this->marina22, 'marina-28' => $this->marina28, 'wologuede-28' => $this->wologuede28];
    }

    /** Oublie les séances déjà calculées (après un changement de réglage dans /admin). */
    public function refresh(): void
    {
        $this->seances = null;
    }

    /** Première séance encore à venir et en vente, sinon première à venir. */
    public function prochaine(): ?Seance
    {
        $now = new \DateTimeImmutable();
        $avenir = array_filter($this->seances(), static fn (Seance $s): bool => $s->startsAt > $now);

        foreach ($avenir as $seance) {
            if ($seance->onSale()) {
                return $seance;
            }
        }

        return array_values($avenir)[0] ?? null;
    }

    public function enVente(): bool
    {
        foreach ($this->seances() as $seance) {
            if ($seance->onSale()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ouvre le paiement chez Tick Africa.
     *
     * @return array{order_id: string, payment_url: string}
     *
     * @throws TickAfricaUnavailable
     */
    public function commander(Commande $commande, string $returnUrl): array
    {
        $seance = $this->seances()[$commande->seanceKey()];
        $data = $this->tickAfrica->createOrder($commande->toOrder($seance, $returnUrl), $commande->idempotencyKey);

        $url = (string) ($data['payment_url'] ?? '');
        if (!str_starts_with($url, 'https://')) {
            $this->logger->error('Tick Africa : lien de paiement absent ou invalide.', ['response' => $data]);

            throw new TickAfricaUnavailable('Lien de paiement absent.', 201, null, 'NO_PAYMENT_URL', [], 'Champs reçus : '.implode(', ', array_keys($data)).('' !== $url ? ' · payment_url = '.$url : ''));
        }

        // Les places affichées changent dès qu'un paiement aboutit : on relira le stock
        $this->cache->deleteItem($this->cacheKey($seance->eventId));

        return ['order_id' => (string) ($data['order_id'] ?? ''), 'payment_url' => $url];
    }

    /** Garde la dernière commande échouée, pour l'afficher dans /admin (sans données personnelles). */
    public function recordFailure(TickAfricaUnavailable $e, string $seance): void
    {
        $item = $this->cache->getItem('billetterie.last_failure');
        $this->cache->save($item->set([
            'at' => (new \DateTimeImmutable())->format('c'),
            'seance' => $seance,
            'status' => $e->getCode(),
            'error' => $e->apiError,
            'message' => $e->apiMessage ?: $e->getMessage(),
            'fields' => array_keys($e->fieldErrors),
        ])->expiresAfter(86400 * 14));
    }

    /**
     * @return array{at: string, seance: string, status: int, error: string, message: string, fields: list<string>}|null
     */
    public function lastFailure(): ?array
    {
        $item = $this->cache->getItem('billetterie.last_failure');

        return $item->isHit() ? $item->get() : null;
    }

    /** Ce que signifie une erreur de commande et ce qu'il faut faire, pour /admin. */
    public static function explain(string $error, int $status): string
    {
        return match (true) {
            'MISSING_SCOPE' === $error => 'La clé n\'a pas le droit de créer des commandes : ajoutez-lui la portée orders:write dans le back-office Tick Africa (API & intégrations).',
            'INVALID_API_KEY' === $error, 401 === $status => 'Tick Africa refuse la clé : régénérez-la et saisissez-la à nouveau.',
            'VALIDATION_ERROR' === $error => 'Tick Africa refuse des données de la commande (champs en cause ci-dessous). Si c\'est return_url : enregistrez l\'URL de retour sur la clé.',
            'IDEMPOTENCY_KEY_REUSED' === $error => 'Même tentative renvoyée avec des données différentes. Le site réessaie désormais tout seul : ce cas ne devrait plus bloquer l\'acheteur.',
            'EVENT_REQUIRES_SEAT_SELECTION' === $error => 'Événement avec plan de salle : non vendable par l\'API. Passez-le en placement libre.',
            'EVENT_NOT_ON_SALE' === $error, 'PRODUCT_UNAVAILABLE' === $error => 'L\'événement ou le billet n\'est pas en vente chez Tick Africa.',
            'NO_PAYMENT_URL' === $error => 'Tick Africa a accepté la commande mais n\'a pas renvoyé de lien de paiement exploitable.',
            'RATE_LIMITED' === $error => 'Trop de commandes en une minute (limite Tick Africa : 60/min).',
            0 === $status => 'Tick Africa n\'a pas répondu à temps (réseau ou panne). Réessayez ; si cela persiste, contactez support@tick.africa.',
            $status >= 500 => 'Panne chez Tick Africa. Réessayez plus tard ou contactez support@tick.africa.',
            default => 'Erreur non répertoriée : transmettez ce message au support Tick Africa.',
        };
    }

    /** Message à montrer à l'acheteur quand Tick Africa refuse ou ne répond pas. */
    public static function message(TickAfricaUnavailable $e): string
    {
        return match ($e->apiError) {
            'INSUFFICIENT_STOCK' => 'Il ne reste plus assez de places pour cette séance. Réduisez le nombre de billets ou choisissez une autre séance.',
            'PRODUCT_UNAVAILABLE', 'EVENT_NOT_ON_SALE', 'EVENT_REQUIRES_SEAT_SELECTION' => 'Cette séance n\'est plus en vente. Choisissez-en une autre.',
            'VALIDATION_ERROR' => 'Vérifiez vos coordonnées : Tick Africa ne les a pas acceptées.',
            'RATE_LIMITED', 'REQUEST_IN_PROGRESS' => 'Beaucoup de monde réserve en ce moment. Réessayez dans quelques secondes.',
            'MISSING_SCOPE', 'INVALID_API_KEY', 'NO_PAYMENT_URL' => 'La vente en ligne est momentanément indisponible. Réessayez un peu plus tard ou écrivez-nous sur WhatsApp au 0166617878 : vous n\'avez pas été débité.',
            default => 'La billetterie ne répond pas pour le moment. Réessayez dans un instant : vous n\'avez pas été débité.',
        };
    }

    /**
     * Champ du formulaire en cause dans un VALIDATION_ERROR de Tick Africa.
     *
     * @return array<string, string>
     */
    public static function fieldErrors(TickAfricaUnavailable $e): array
    {
        $map = ['customer.first_name' => 'prenom', 'customer.last_name' => 'nom', 'customer.email' => 'email', 'customer.phone' => 'telephone', 'items.0.quantity' => 'quantite'];
        $errors = [];
        foreach ($e->fieldErrors as $field => $messages) {
            if (isset($map[$field])) {
                $errors[$map[$field]] = \is_array($messages) ? (string) reset($messages) : (string) $messages;
            }
        }

        return $errors;
    }

    private function build(string $key, \DateTimeImmutable $start, string $salle, string $link, bool $forcedSoldOut, bool $live): Seance
    {
        [$eventId, $productIds] = self::parseLink($link);
        $base = [$key, $start, self::SALLES[$salle]];

        if ($forcedSoldOut) {
            return new Seance(...$base, status: Seance::COMPLET, liveAccess: $live, eventId: $eventId, reason: 'complet_force');
        }
        if ($start <= new \DateTimeImmutable()) {
            return new Seance(...$base, status: Seance::PASSEE, eventId: $eventId, reason: 'passee');
        }
        if ('' === $eventId || !$this->tickAfrica->isConfigured()) {
            return new Seance(...$base, status: Seance::INDISPONIBLE, eventId: $eventId, reason: '' === $eventId ? 'non_reliee' : 'sans_cle');
        }

        [$event, $stale, $notFound] = $this->event($eventId);
        if ($notFound) {
            return new Seance(...$base, status: Seance::FERMEE, eventId: $eventId, reason: 'introuvable');
        }
        if (null === $event) {
            return new Seance(...$base, status: Seance::INDISPONIBLE, eventId: $eventId, reason: 'injoignable');
        }

        $products = array_values(array_filter(\is_array($event['products'] ?? null) ? $event['products'] : [], 'is_array'));
        if ([] !== $productIds) {
            // Billets choisis dans /admin
            $products = array_filter($products, static fn (array $p): bool => \in_array(strtolower((string) ($p['id'] ?? '')), $productIds, true));
        } else {
            // Sinon les places (type « ticket »), en écartant boissons et goodies…
            // sauf si Tick Africa nomme autrement ses places : on garde alors tout
            $places = array_filter($products, static fn (array $p): bool => 'ticket' === ($p['type'] ?? 'ticket'));
            $products = [] !== $places ? $places : $products;
        }
        $tarifs = array_values(array_filter(array_map(Tarif::fromApi(...), $products)));

        [$status, $reason] = match (true) {
            !($event['sellable_via_api'] ?? false) => [Seance::FERMEE, 'plan_de_salle'],
            !($event['is_on_sale'] ?? false) => [Seance::FERMEE, 'pas_en_vente'],
            [] === $tarifs => [Seance::FERMEE, [] !== $productIds ? 'billets_non_trouves' : 'aucun_billet'],
            [] === array_filter($tarifs, static fn (Tarif $t): bool => $t->available) => [Seance::COMPLET, 'epuise'],
            default => [Seance::EN_VENTE, ''],
        };

        return new Seance(...$base, status: $status, tarifs: $tarifs, eventId: $eventId, stale: $stale, reason: $reason, eventName: (string) ($event['name'] ?? ''));
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: bool, 2: bool} [événement, relevé ancien, introuvable]
     */
    private function event(string $eventId): array
    {
        $item = $this->cache->getItem($this->cacheKey($eventId));
        if ($item->isHit()) {
            return $item->get();
        }

        $last = $this->cache->getItem($this->cacheKey($eventId).'.last');

        try {
            $value = [$this->tickAfrica->event($eventId), false, false];
            $item->expiresAfter(self::FRESH_TTL);
            $this->cache->save($last->set($value[0])->expiresAfter(self::LAST_GOOD_TTL));
        } catch (TickAfricaUnavailable $e) {
            // Introuvable : l'événement n'est plus publié (ou n'est pas à nous)
            $value = 404 === $e->getCode() ? [null, false, true] : [$last->isHit() ? $last->get() : null, true, false];
            $item->expiresAfter(self::RETRY_TTL); // on ne martèle pas une API en difficulté
        }

        $this->cache->save($item->set($value));

        return $value;
    }

    private function cacheKey(string $eventId): string
    {
        return 'tickafrica.event.'.$eventId;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function parseLink(string $link): array
    {
        [$event, $products] = array_pad(explode(':', trim($link), 2), 2, '');
        $event = LiveSettings::normalizeUuid($event);
        $products = array_values(array_filter(array_map(
            static fn (string $id): string => LiveSettings::normalizeUuid($id),
            explode(',', $products),
        )));

        return [$event, $products];
    }
}

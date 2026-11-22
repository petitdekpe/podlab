<?php

namespace App\Live;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API Organisateur de Tick Africa (serveur à serveur uniquement).
 *
 * https://tick.africa/fr/docs/api/organizer — clé secrète en Bearer
 * (ta_live_… en production, ta_test_… en test), portée tickets:validate
 * pour les billets, events:read pour lister les événements.
 */
final class TickAfrica
{
    public const DEFAULT_URL = 'https://api.tick.africa/api/partner/v1';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $apiUrl = self::DEFAULT_URL,
        /** Clé saisie dans /admin : elle remplace celle de l'environnement */
        private readonly ?LiveSettings $settings = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->key();
    }

    /** Clé de test : elle ne valide que les billets de test. */
    public function isTestMode(): bool
    {
        return str_starts_with($this->key(), 'ta_test_');
    }

    /** D'où vient la clé utilisée : 'admin', 'env' ou 'none'. */
    public function keySource(): string
    {
        return match (true) {
            '' !== $this->adminKey() => 'admin',
            '' !== trim($this->apiKey) => 'env',
            default => 'none',
        };
    }

    /** Fin de la clé, pour la reconnaître sans l'afficher : « ta_live_…a1b2 ». */
    public function maskedKey(): string
    {
        $key = $this->key();
        if ('' === $key) {
            return '';
        }
        $prefix = preg_match('/^ta_(live|test)_/', $key, $m) ? $m[0] : '';

        return $prefix.'…'.substr($key, -4);
    }

    /** Même client avec une autre clé : pour vérifier une clé avant de l'enregistrer. */
    public function withApiKey(#[\SensitiveParameter] string $apiKey): self
    {
        return new self($this->http, $this->logger, $apiKey, $this->apiUrl);
    }

    /** Clé effective : celle de /admin, sinon TICKAFRICA_API_KEY. */
    private function key(): string
    {
        $admin = $this->adminKey();

        return '' !== $admin ? $admin : trim($this->apiKey);
    }

    private function adminKey(): string
    {
        if (null === $this->settings) {
            return '';
        }
        try {
            return trim((string) $this->settings->tickafricaApiKey());
        } catch (\Throwable) {
            return ''; // base indisponible : on se rabat sur l'environnement
        }
    }

    /**
     * Valide le billet : si Tick Africa l'admet, l'entrée y est enregistrée
     * (usage unique, sauf si l'événement autorise la ressortie).
     *
     * @throws TickAfricaUnavailable
     */
    public function validate(string $code, string $eventId): TicketVerdict
    {
        return $this->ticket('/tickets/validate', $code, $eventId);
    }

    /**
     * Mêmes contrôles que validate(), mais rien n'est enregistré : le billet reste utilisable.
     *
     * @throws TickAfricaUnavailable
     */
    public function check(string $code, string $eventId = ''): TicketVerdict
    {
        return $this->ticket('/tickets/check', $code, $eventId);
    }

    /**
     * Vos événements publiés et en vente (portée events:read).
     *
     * @return list<array<string, mixed>>
     *
     * @throws TickAfricaUnavailable
     */
    public function events(string $search = ''): array
    {
        $query = ['per_page' => 100];
        if ('' !== $search) {
            $query['search'] = $search;
        }
        $data = $this->request('GET', '/events', ['query' => $query]);

        return \is_array($data['data'] ?? null) ? array_values($data['data']) : [];
    }

    /**
     * Un événement, ses dates et ses types de billets avec la disponibilité
     * en temps réel (portée events:read). 404 si l'événement n'est pas à vous.
     *
     * @return array<string, mixed>
     *
     * @throws TickAfricaUnavailable
     */
    public function event(string $eventId): array
    {
        // Lu à chaque affichage de la page (avec cache) : on n'attend pas longtemps
        $data = $this->request('GET', '/events/'.rawurlencode($eventId), ['timeout' => 4, 'max_duration' => 6]);

        return \is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /**
     * Ouvre un paiement et renvoie le lien vers lequel rediriger l'acheteur
     * (portée orders:write). Ce n'est PAS une vente : elle n'existe qu'une fois
     * le paiement confirmé. Rejouer la même clé d'idempotence avec le même
     * corps rend la réponse d'origine, sans second paiement.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed> order_id, reference, status, amount, payment_url…
     *
     * @throws TickAfricaUnavailable ($apiError porte le code métier : INSUFFICIENT_STOCK…)
     */
    public function createOrder(array $order, string $idempotencyKey): array
    {
        $data = $this->request('POST', '/orders', [
            'json' => $order,
            'headers' => ['Idempotency-Key' => $idempotencyKey],
            'timeout' => 15,
            'max_duration' => 25,
        ]);

        return \is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /**
     * Une commande, ou le paiement en attente rendu par createOrder() (portée orders:read).
     *
     * @return array<string, mixed>
     *
     * @throws TickAfricaUnavailable
     */
    public function order(string $orderId): array
    {
        $data = $this->request('GET', '/orders/'.rawurlencode($orderId), ['timeout' => 5, 'max_duration' => 8]);

        return \is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    private function ticket(string $path, string $code, string $eventId): TicketVerdict
    {
        $body = ['code' => $code];
        if ('' !== $eventId) {
            $body['event_id'] = $eventId;
        }

        try {
            $data = $this->request('POST', $path, ['json' => $body]);
        } catch (TickAfricaUnavailable $e) {
            // Code mal formé (trop long, caractères refusés…) : c'est un code invalide, pas une panne
            if (422 === $e->getCode()) {
                return TicketVerdict::refused('invalid_code');
            }
            throw $e;
        }

        return TicketVerdict::fromApi($data['data'] ?? null);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws TickAfricaUnavailable (code = statut HTTP s'il y en a un)
     */
    private function request(string $method, string $path, array $options): array
    {
        if (!$this->isConfigured()) {
            $this->logger->critical('Tick Africa : clé API absente (/admin > Paramètres, ou variable TICKAFRICA_API_KEY).');

            throw new TickAfricaUnavailable('Clé API Tick Africa absente.');
        }

        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];

        try {
            $response = $this->http->request($method, rtrim($this->apiUrl, '/').$path, $options + [
                'auth_bearer' => $this->key(),
                'timeout' => 8,
                'max_duration' => 12,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            $this->logger->error('Tick Africa injoignable ou réponse illisible : {message}', ['message' => $e->getMessage(), 'path' => $path]);

            throw new TickAfricaUnavailable('Tick Africa injoignable.', 0, $e);
        }

        if (200 === $status || 201 === $status) {
            return $data;
        }

        $error = \is_string($data['error'] ?? null) ? $data['error'] : 'HTTP_'.$status;
        $fieldErrors = \is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $apiMessage = \is_string($data['message'] ?? null) ? $data['message'] : '';
        $context = ['status' => $status, 'error' => $error, 'message' => $apiMessage, 'errors' => $fieldErrors, 'path' => $path];

        // Commande : un refus métier (stock épuisé, téléphone invalide…) n'est pas une panne
        if ('/orders' === $path && \in_array($status, [409, 422], true)) {
            $this->logger->warning('Tick Africa a refusé la commande : {error} ({message})', $context);

            throw new TickAfricaUnavailable(sprintf('Commande refusée par Tick Africa (%s).', $error), $status, null, $error, $fieldErrors, $apiMessage);
        }

        // 422 sur le seul champ code : le spectateur a saisi n'importe quoi, ce n'est pas une panne
        $codeOnly = 422 === $status && \is_array($data['errors'] ?? null) && [] === array_diff(array_keys($data['errors']), ['code']);

        if ($codeOnly) {
            throw new TickAfricaUnavailable('Code refusé par Tick Africa.', 422);
        }

        // 401 (clé), 403 (portée manquante), 404 (événement inconnu)… : problème de configuration
        $this->logger->log(\in_array($status, [401, 403, 404, 422], true) ? 'critical' : 'error', 'Tick Africa a répondu {status} {error} : {message}', $context);

        throw new TickAfricaUnavailable(sprintf('Tick Africa a répondu %d (%s).', $status, $error), 422 === $status ? 0 : $status, null, $error, $fieldErrors, $apiMessage);
    }

    /**
     * Vérifie chaque portée de la clé SANS rien créer ni consommer :
     * - events:read     GET /events
     * - orders:write    POST /orders avec un corps vide : 422 (refusé pour données invalides) = autorisé, 403 = portée absente
     * - orders:read     GET /orders?per_page=1
     * - tickets:validate POST /tickets/check sur un code bidon (vérification seule, rien n'est enregistré)
     *
     * @return array<string, array{ok: bool|null, detail: string}> ok = null si le résultat n'est pas concluant
     */
    public function diagnose(): array
    {
        $probes = [
            'events:read' => ['GET', '/events', ['query' => ['per_page' => 1]]],
            'orders:write' => ['POST', '/orders', ['json' => new \stdClass(), 'headers' => ['Idempotency-Key' => 'diagnostic-'.bin2hex(random_bytes(8))]]],
            'orders:read' => ['GET', '/orders', ['query' => ['per_page' => 1]]],
            'tickets:validate' => ['POST', '/tickets/check', ['json' => ['code' => 'DIAGNOSTIC']]],
        ];

        $results = [];
        foreach ($probes as $scope => [$method, $path, $options]) {
            if (!$this->isConfigured()) {
                $results[$scope] = ['ok' => false, 'detail' => 'Aucune clé.'];
                continue;
            }
            try {
                $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
                $response = $this->http->request($method, rtrim($this->apiUrl, '/').$path, $options + ['auth_bearer' => $this->key(), 'timeout' => 8, 'max_duration' => 12]);
                $status = $response->getStatusCode();
                $data = $response->toArray(false);
            } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
                $results[$scope] = ['ok' => null, 'detail' => 'Tick Africa ne répond pas ('.$e->getMessage().').'];
                continue;
            }

            $error = \is_string($data['error'] ?? null) ? $data['error'] : '';
            $message = \is_string($data['message'] ?? null) ? $data['message'] : '';
            $results[$scope] = match (true) {
                401 === $status => ['ok' => false, 'detail' => 'Clé refusée (401 '.$error.').'],
                403 === $status => ['ok' => false, 'detail' => 'Portée absente (403 '.$error.') '.$message],
                // POST /orders vide : la requête passe le contrôle d'accès puis échoue à la validation
                200 === $status, 201 === $status, 422 === $status, 409 === $status => ['ok' => true, 'detail' => 'Autorisé (réponse '.$status.').'],
                429 === $status => ['ok' => null, 'detail' => 'Trop de requêtes, réessayez dans une minute.'],
                default => ['ok' => null, 'detail' => sprintf('Réponse inattendue %d %s %s', $status, $error, $message)],
            };
        }

        return $results;
    }
}

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
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /** Clé de test : elle ne valide que les billets de test. */
    public function isTestMode(): bool
    {
        return str_starts_with(trim($this->apiKey), 'ta_test_');
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
            $this->logger->critical('Tick Africa : clé API absente (variable TICKAFRICA_API_KEY).');

            throw new TickAfricaUnavailable('Clé API Tick Africa absente.');
        }

        try {
            $response = $this->http->request($method, rtrim($this->apiUrl, '/').$path, $options + [
                'auth_bearer' => trim($this->apiKey),
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 8,
                'max_duration' => 12,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            $this->logger->error('Tick Africa injoignable ou réponse illisible : {message}', ['message' => $e->getMessage(), 'path' => $path]);

            throw new TickAfricaUnavailable('Tick Africa injoignable.', 0, $e);
        }

        if (200 === $status) {
            return $data;
        }

        $error = \is_string($data['error'] ?? null) ? $data['error'] : 'HTTP_'.$status;
        $context = ['status' => $status, 'error' => $error, 'message' => $data['message'] ?? null, 'path' => $path];

        // 422 sur le seul champ code : le spectateur a saisi n'importe quoi, ce n'est pas une panne
        $codeOnly = 422 === $status && \is_array($data['errors'] ?? null) && [] === array_diff(array_keys($data['errors']), ['code']);

        if ($codeOnly) {
            throw new TickAfricaUnavailable('Code refusé par Tick Africa.', 422);
        }

        // 401 (clé), 403 (portée manquante), 404 (événement inconnu)… : problème de configuration
        $this->logger->log(\in_array($status, [401, 403, 404, 422], true) ? 'critical' : 'error', 'Tick Africa a répondu {status} {error} : {message}', $context);

        throw new TickAfricaUnavailable(sprintf('Tick Africa a répondu %d (%s).', $status, $error), 422 === $status ? 0 : $status);
    }
}

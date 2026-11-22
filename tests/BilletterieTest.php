<?php

namespace App\Tests;

use App\Billetterie\Billetterie;
use App\Billetterie\Commande;
use App\Billetterie\Seance;
use App\Live\LiveConfig;
use App\Live\LiveSettings;
use App\Live\TickAfrica;
use App\Live\TickAfricaUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BilletterieTest extends TestCase
{
    private const MARINA = '11111111-1111-4111-8111-111111111111';
    private const WOLO = '22222222-2222-4222-8222-222222222222';
    private const TARIF_MARINA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const TARIF_WOLO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $calls = [];

    public function testSeancesReadAvailabilityFromTickAfrica(): void
    {
        $billetterie = $this->billetterie([
            self::MARINA => $this->event(self::TARIF_MARINA, 12),
            self::WOLO => $this->event(self::TARIF_WOLO, 0, false),
        ]);

        $seances = $billetterie->seances();

        self::assertSame(['marina-22', 'marina-28', 'wologuede-28'], array_keys($seances));
        // 22 : complète par défaut (réglage /admin), avec le direct
        self::assertSame(Seance::COMPLET, $seances['marina-22']->status);
        // le direct est proposé jusqu'à sa fin (22/11, 16h00), plus après
        self::assertSame(new \DateTimeImmutable() < new \DateTimeImmutable('2026-11-22T16:00:00+01:00'), $seances['marina-22']->liveAccess);
        // 29 Marina : en vente, 12 places
        self::assertSame(Seance::EN_VENTE, $seances['marina-28']->status);
        self::assertSame(12, $seances['marina-28']->placesRestantes());
        self::assertSame(10000, $seances['marina-28']->prixMin());
        // 29 Wologuèdè : plus de stock
        self::assertSame(Seance::COMPLET, $seances['wologuede-28']->status);
        self::assertSame('Dimanche 29 novembre', $seances['wologuede-28']->jour());
        self::assertSame('13h00', $seances['wologuede-28']->heure());
        self::assertSame($seances['marina-28'], $billetterie->prochaine());
    }

    public function testCatalogueIsCachedAndLastGoodReadingSurvivesAnOutage(): void
    {
        $cache = new ArrayAdapter();
        $this->billetterie([self::MARINA => $this->event(self::TARIF_MARINA, 40)], $cache)->seances();
        $this->billetterie([self::MARINA => $this->event(self::TARIF_MARINA, 40)], $cache)->seances();
        self::assertCount(1, array_filter($this->calls, fn ($c) => str_contains($c['url'], self::MARINA)), 'GET /events/{id} mis en cache');

        // Le cache frais expire, Tick Africa tombe : on garde le dernier relevé
        $cache->deleteItem('tickafrica.event.'.self::MARINA);
        $seance = $this->billetterie([self::MARINA => new MockResponse('', ['http_code' => 503])], $cache)->seances()['marina-28'];

        self::assertSame(Seance::EN_VENTE, $seance->status);
        self::assertTrue($seance->stale);
    }

    public function testClosedSeanceExplainsWhy(): void
    {
        $seated = $this->event(self::TARIF_MARINA, 10);
        $seated['data']['sellable_via_api'] = false;
        $offSale = $this->event(self::TARIF_WOLO, 10);
        $offSale['data']['is_on_sale'] = false;

        $seances = $this->billetterie([self::MARINA => $seated, self::WOLO => $offSale])->seances();

        self::assertSame([Seance::FERMEE, 'plan_de_salle'], [$seances['marina-28']->status, $seances['marina-28']->reason]);
        self::assertSame([Seance::FERMEE, 'pas_en_vente'], [$seances['wologuede-28']->status, $seances['wologuede-28']->reason]);
        self::assertSame('Podlab au cinéma', $seances['marina-28']->eventName);

        // 404 : identifiant erroné ou événement d'un autre compte
        $missing = $this->billetterie([self::WOLO => $offSale], wologuede: '55555555-5555-4555-8555-555555555555')->seances();
        self::assertSame('introuvable', $missing['wologuede-28']->reason);
    }

    public function testTicketsWithAnotherTypeAreStillSold(): void
    {
        // Si Tick Africa ne type pas ses places « ticket », on ne les écarte pas toutes
        $event = $this->event(self::TARIF_MARINA, 10);
        $event['data']['products'][0]['type'] = 'standard';

        $seance = $this->billetterie([self::MARINA => $event])->seances()['marina-28'];

        self::assertSame(Seance::EN_VENTE, $seance->status);
    }

    public function testUnconfiguredSeanceIsShownAsComingSoon(): void
    {
        $seances = $this->billetterie([], null, wologuede: '')->seances();

        self::assertSame(Seance::INDISPONIBLE, $seances['wologuede-28']->status);
    }

    public function testProductFilterKeepsOnlyTheSeanceTickets(): void
    {
        // Les deux séances du 29 dans un seul événement Tick Africa, un billet par salle
        $event = $this->event(self::TARIF_MARINA, 30);
        $event['data']['products'][] = ['id' => self::TARIF_WOLO, 'name' => 'Wologuèdè', 'type' => 'ticket', 'price' => '8000.00', 'available_quantity' => 5, 'is_available' => true];

        $seances = $this->billetterie([self::MARINA => $event], null, marina: self::MARINA.':'.self::TARIF_MARINA, wologuede: self::MARINA.':'.self::TARIF_WOLO)->seances();

        self::assertSame([self::TARIF_MARINA], array_map(fn ($t) => $t->id, $seances['marina-28']->tarifs));
        self::assertSame(8000, $seances['wologuede-28']->prixMin());
    }

    public function testOrderIsSentWithIdempotencyKeyAndReturnsPaymentLink(): void
    {
        $billetterie = $this->billetterie([
            self::MARINA => $this->event(self::TARIF_MARINA, 12),
            'orders' => new MockResponse(json_encode(['success' => true, 'data' => [
                'order_id' => 'pay-1', 'status' => 'pending', 'payment_url' => 'https://checkout.fedapay.com/xyz',
            ]]), ['http_code' => 201]),
        ]);

        $commande = new Commande('marina-28|'.self::TARIF_MARINA, 3, 'Awa', 'Diallo', 'awa@example.com', '01 66 61 78 78');
        self::assertTrue($commande->validate($billetterie->seances()), json_encode($commande->errors));

        $paiement = $billetterie->commander($commande, 'https://podlab.test/billetterie/merci');

        self::assertSame('https://checkout.fedapay.com/xyz', $paiement['payment_url']);
        $post = array_values(array_filter($this->calls, fn ($c) => 'POST' === $c['method']))[0];
        self::assertStringEndsWith('/orders', $post['url']);
        self::assertContains('Idempotency-Key: '.$commande->idempotencyKey, $post['options']['headers']);
        $body = json_decode($post['options']['body'], true);
        self::assertSame(self::MARINA, $body['event_id']);
        self::assertSame([['product_id' => self::TARIF_MARINA, 'quantity' => 3]], $body['items']);
        self::assertSame('+2290166617878', $body['customer']['phone']);
        self::assertSame('https://podlab.test/billetterie/merci', $body['return_url']);
    }

    public function testBusinessRefusalCarriesTheStableErrorCode(): void
    {
        $billetterie = $this->billetterie([
            self::MARINA => $this->event(self::TARIF_MARINA, 2),
            'orders' => new MockResponse(json_encode(['error' => 'INSUFFICIENT_STOCK', 'message' => 'Il ne reste que 2 billets.']), ['http_code' => 422]),
        ]);
        $commande = new Commande('marina-28|'.self::TARIF_MARINA, 2, 'Awa', 'Diallo', 'awa@example.com', '0166617878');
        $billetterie->seances();

        try {
            $billetterie->commander($commande, '');
            self::fail('Refus attendu');
        } catch (TickAfricaUnavailable $e) {
            self::assertSame('INSUFFICIENT_STOCK', $e->apiError);
            self::assertStringContainsString('plus assez de places', Billetterie::message($e));
        }
    }

    public function testMissingOrdersScopeIsRecordedAndExplained(): void
    {
        $billetterie = $this->billetterie([
            self::MARINA => $this->event(self::TARIF_MARINA, 12),
            'orders' => new MockResponse(json_encode(['error' => 'MISSING_SCOPE', 'message' => 'This key lacks the orders:write scope.']), ['http_code' => 403]),
        ]);
        $commande = new Commande('marina-28|'.self::TARIF_MARINA, 1, 'Awa', 'Diallo', 'awa@example.com', '0166617878');
        $billetterie->seances();

        try {
            $billetterie->commander($commande, '');
            self::fail('Refus attendu');
        } catch (TickAfricaUnavailable $e) {
            $billetterie->recordFailure($e, 'marina-28');
            self::assertStringContainsString('momentanément indisponible', Billetterie::message($e));
        }

        $failure = $billetterie->lastFailure();
        self::assertSame([403, 'MISSING_SCOPE', 'This key lacks the orders:write scope.'], [$failure['status'], $failure['error'], $failure['message']]);
        self::assertStringContainsString('orders:write', Billetterie::explain($failure['error'], $failure['status']));
    }

    public function testReturnUrlIsAlwaysHttpsExceptLocally(): void
    {
        self::assertSame('https://podlab.beninbouge.com/billetterie/merci', LiveSettings::defaultReturnUrl('http://podlab.beninbouge.com/billetterie/merci'));
        self::assertSame('https://podlab.beninbouge.com/billetterie/merci', LiveSettings::defaultReturnUrl('https://podlab.beninbouge.com/billetterie/merci'));
        self::assertSame('http://localhost/billetterie/merci', LiveSettings::defaultReturnUrl('http://localhost/billetterie/merci'));
        self::assertStringContainsString('à l\'identique', Billetterie::explain('RETURN_URL_NOT_REGISTERED', 422));
    }

    public function testDiagnoseReportsEachScopeWithoutSelling(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $method.' '.parse_url($url, \PHP_URL_PATH);

            return match (true) {
                str_ends_with(parse_url($url, \PHP_URL_PATH), '/orders') && 'POST' === $method => new MockResponse(json_encode(['error' => 'MISSING_SCOPE', 'message' => 'orders:write']), ['http_code' => 403]),
                str_contains($url, '/tickets/check') => new MockResponse(json_encode(['success' => true, 'data' => ['valid' => false, 'reason' => 'invalid_code']])),
                default => new MockResponse(json_encode(['success' => true, 'data' => []])),
            };
        });

        $results = (new TickAfrica($http, new NullLogger(), 'ta_test_cle'))->diagnose();

        self::assertTrue($results['events:read']['ok']);
        self::assertFalse($results['orders:write']['ok']);
        self::assertTrue($results['orders:read']['ok']);
        self::assertTrue($results['tickets:validate']['ok']);
        self::assertNotContains('POST /api/partner/v1/tickets/validate', $calls, 'aucune entrée enregistrée');
    }

    public function testFormValidation(): void
    {
        $seances = $this->billetterie([self::MARINA => $this->event(self::TARIF_MARINA, 4)])->seances();

        $commande = new Commande('marina-28|'.self::TARIF_MARINA, 5, '', 'Diallo', 'pas-un-email', '12');
        self::assertFalse($commande->validate($seances));
        self::assertSame(['quantite', 'prenom', 'email', 'telephone'], array_keys($commande->errors));
        self::assertStringContainsString('que 4 places', $commande->errors['quantite']);

        $complet = new Commande('marina-22|x', 1, 'Awa', 'Diallo', 'awa@example.com', '0166617878');
        self::assertFalse($complet->validate($seances));
        self::assertArrayHasKey('billet', $complet->errors);
    }

    public function testPhoneNormalization(): void
    {
        self::assertSame('+2290166617878', Commande::normalizePhone('01 66 61 78 78'));
        self::assertSame('+2290166617878', Commande::normalizePhone('+229 01.66.61.78.78'));
        self::assertSame('+2290166617878', Commande::normalizePhone('66617878'));
        self::assertSame('+33612345678', Commande::normalizePhone('0033 6 12 34 56 78'));
        self::assertSame('', Commande::normalizePhone('12'));
    }

    /**
     * @param array<string, array<string, mixed>|MockResponse> $responses par UUID d'événement, ou 'orders'
     */
    private function billetterie(array $responses, ?ArrayAdapter $cache = null, ?string $marina = null, ?string $wologuede = null): Billetterie
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($responses): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];
            foreach ($responses as $key => $response) {
                if (str_contains($url, '/'.$key)) {
                    return $response instanceof MockResponse ? $response : new MockResponse(json_encode($response));
                }
            }

            return new MockResponse(json_encode(['error' => 'NOT_FOUND']), ['http_code' => 404]);
        });

        // Base indisponible : les réglages /admin prennent leur valeur par défaut
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willThrowException(new \RuntimeException('pas de base'));
        $em->method('find')->willThrowException(new \RuntimeException('pas de base'));
        $em->method('createQueryBuilder')->willThrowException(new \RuntimeException('pas de base'));
        $em->method('getConnection')->willThrowException(new \RuntimeException('pas de base'));
        $settings = new LiveSettings($em, new LiveConfig('', '2026-11-22T12:30:00+01:00', '2099-01-01T00:00:00+01:00', '', ''));

        return new Billetterie(
            new TickAfrica($http, new NullLogger(), 'ta_test_cle'),
            $settings,
            $cache ?? new ArrayAdapter(),
            new NullLogger(),
            '',
            $marina ?? self::MARINA,
            $wologuede ?? self::WOLO,
        );
    }

    /** @return array<string, mixed> réponse de GET /events/{id} */
    private function event(string $tarif, int $restantes, bool $disponible = true): array
    {
        return ['success' => true, 'data' => [
            'id' => 'x', 'name' => 'Podlab au cinéma', 'is_on_sale' => true, 'sellable_via_api' => true,
            'products' => [['id' => $tarif, 'name' => 'Billet unique', 'type' => 'ticket', 'price' => '10000.00', 'available_quantity' => $restantes, 'is_available' => $disponible]],
        ]];
    }
}

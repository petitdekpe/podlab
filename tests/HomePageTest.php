<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HomePageTest extends WebTestCase
{
    private const MARINA = '11111111-1111-4111-8111-111111111111';
    private const WOLO = '22222222-2222-4222-8222-222222222222';
    private const TARIF = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /** @var list<array{method: string, url: string, body: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        foreach ([
            'TICKAFRICA_API_KEY' => 'ta_test_cle',
            'TICKAFRICA_SEANCE_MARINA_28' => self::MARINA,
            'TICKAFRICA_SEANCE_WOLOGUEDE_28' => self::WOLO,
        ] as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach (['TICKAFRICA_API_KEY', 'TICKAFRICA_SEANCE_MARINA_28', 'TICKAFRICA_SEANCE_WOLOGUEDE_28'] as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        parent::tearDown();
    }

    public function testHomeShowsTheThreeSeances(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // Premier écran : l'affiche puis la billetterie, dans la même section
        self::assertCount(1, $crawler->filter('#reservation .top__poster .poster img'));
        self::assertCount(1, $crawler->filter('#reservation .top__ticketing form.order'));
        self::assertSelectorTextContains('h1', 'Moi, à quatre pattes');
        // Trois séances : le 22 complet (avec le direct), les deux du 29 en vente
        self::assertCount(3, $crawler->filter('input[name="billet"]'));
        self::assertCount(2, $crawler->filter('input[name="billet"]:not([disabled])'));
        self::assertStringContainsString('Majestic Wologuèdè', $crawler->filter('form.order')->text());
        // Un cadran par séance ; celui du 22 est « Sold out » et mène au direct
        self::assertCount(3, $crawler->filter('form.order .dial'));
        self::assertSame('Sold out', $crawler->filter('.dial.is-soldout .dial__stamp')->text());
        self::assertSame('22', $crawler->filter('.dial.is-soldout .dial__day')->text());
        self::assertSelectorExists('.dial.is-soldout .dial__live[data-open-modal="live-tickets"]');
        self::assertCount(3, $crawler->filter('.seance-strip__item'));
        self::assertSelectorExists('dialog[data-modal-id-value="live-tickets"]');
    }

    public function testValidFormRedirectsToTickAfricaPayment(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', '/');

        $form = $crawler->filter('form.order')->form([
            'billet' => 'wologuede-28|'.self::TARIF,
            'quantite' => '2',
            'prenom' => 'Awa',
            'nom' => 'Diallo',
            'email' => 'awa@example.com',
            'telephone' => '01 66 61 78 78',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('https://checkout.fedapay.com/test', 303);
        $post = array_values(array_filter($this->calls, fn ($c) => 'POST' === $c['method']))[0];
        $body = json_decode($post['body'], true);
        self::assertSame(self::WOLO, $body['event_id']);
        self::assertSame(2, $body['items'][0]['quantity']);
        self::assertStringEndsWith('/billetterie/merci', $body['return_url']);
    }

    public function testInvalidFormIsShownAgainWithErrors(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', '/');

        $client->submit($crawler->filter('form.order')->form([
            'billet' => 'marina-28|'.self::TARIF,
            'prenom' => 'Awa',
            'nom' => 'Diallo',
            'email' => 'faux',
            'telephone' => '0166617878',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.order__error', 'e-mail');
        self::assertInputValueSame('prenom', 'Awa');
        self::assertCount(0, array_filter($this->calls, fn ($c) => 'POST' === $c['method']));
    }

    public function testThanksPage(): void
    {
        $client = $this->client();
        $client->request('GET', '/billetterie/merci');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Merci');
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot(); // garde le faux Tick Africa d'une requête à l'autre
        $event = json_encode(['success' => true, 'data' => [
            'id' => 'x', 'is_on_sale' => true, 'sellable_via_api' => true,
            'products' => [['id' => self::TARIF, 'name' => 'Billet unique', 'type' => 'ticket', 'price' => '10000.00', 'available_quantity' => 50, 'is_available' => true]],
        ]]);

        static::getContainer()->set('http_client', new MockHttpClient(function (string $method, string $url, array $options) use ($event): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return str_ends_with($url, '/orders')
                ? new MockResponse(json_encode(['success' => true, 'data' => ['order_id' => 'pay-1', 'payment_url' => 'https://checkout.fedapay.com/test']]), ['http_code' => 201])
                : new MockResponse($event);
        }));
        static::getContainer()->get('cache.app')->clear();

        return $client;
    }
}

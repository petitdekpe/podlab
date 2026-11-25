<?php

namespace App\Tests;

use App\Entity\Setting;
use App\Live\LiveConfig;
use App\Live\LiveSettings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AnalyticsTest extends WebTestCase
{
    private const MARINA = '11111111-1111-4111-8111-111111111111';
    private const TARIF = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ENV = ['TICKAFRICA_API_KEY' => 'ta_live_cle', 'TICKAFRICA_SEANCE_MARINA_28' => self::MARINA, 'VENTES_CODE' => '1003'];

    private string $statut = 'validated';

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENV) as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        parent::tearDown();
    }

    public function testNothingWithoutMeasurementId(): void
    {
        $this->client('')->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.cookies');
        self::assertSelectorNotExists('[data-cookies]');
    }

    public function testPublicPagesOnlyAndNoGoogleScriptBeforeConsent(): void
    {
        $client = $this->client('G-TEST123456');

        foreach (['/', '/billetterie/merci'] as $url) {
            $client->request('GET', $url);
            self::assertSelectorExists('.cookies[hidden][data-analytics-id-value="G-TEST123456"]');
            self::assertStringNotContainsString('googletagmanager', (string) $client->getResponse()->getContent());
        }
        foreach (['/ventes', '/admin/connexion'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.cookies');
        }
    }

    public function testConfirmedPurchaseIsMeasuredWithoutPersonalData(): void
    {
        $client = $this->client('G-TEST123456');
        $this->commander($client);
        $crawler = $client->request('GET', '/billetterie/merci');

        $bandeau = $crawler->filter('.cookies');
        $achat = (string) $bandeau->attr('data-analytics-purchase-value');
        self::assertSame([
            'transaction_id' => 'pay-1',
            'currency' => 'XOF',
            'value' => 20000,
            'items' => [['item_id' => 'marina-28', 'item_name' => 'Majestic Marina · Dim. 29 nov.', 'item_variant' => 'Billet unique', 'price' => 10000, 'quantity' => 2]],
        ], json_decode($achat, true));
        self::assertStringNotContainsStringIgnoringCase('awa', $achat);
        self::assertSame('true', $bandeau->attr('data-analytics-return-value'));
    }

    public function testUnconfirmedOrTestPurchaseIsNotMeasured(): void
    {
        $client = $this->client('G-TEST123456');
        $this->commander($client);
        $this->statut = 'pending';
        $crawler = $client->request('GET', '/billetterie/merci');
        self::assertNull($crawler->filter('.cookies')->attr('data-analytics-purchase-value'));
        self::assertSame('true', $crawler->filter('.cookies')->attr('data-analytics-page-view-value'));

        // Relecture automatique : la page n'est pas comptée une deuxième fois
        $crawler = $client->request('GET', '/billetterie/merci?n=1');
        self::assertSame('false', $crawler->filter('.cookies')->attr('data-analytics-page-view-value'));
    }

    public function testTestKeyPurchaseIsNotMeasured(): void
    {
        $client = $this->client('G-TEST123456', 'ta_test_cle');
        $this->commander($client);
        $crawler = $client->request('GET', '/billetterie/merci');

        self::assertSelectorTextContains('.eyebrow', 'Paiement confirmé');
        self::assertNull($crawler->filter('.cookies')->attr('data-analytics-purchase-value'));
    }

    public function testMeasurementIdFormat(): void
    {
        self::assertSame('G-AB12CD34EF', LiveSettings::normalizeAnalyticsId(' g-ab12cd34ef '));
        foreach (['', 'UA-12345-1', 'GTM-ABC123', 'G-"><script>'] as $invalide) {
            self::assertSame('', LiveSettings::normalizeAnalyticsId($invalide));
        }
    }

    public function testPixelAloneShowsTheBannerWithoutLoadingMeta(): void
    {
        $client = $this->client('', pixel: '123456789012345');

        foreach (['/', '/billetterie/merci'] as $url) {
            $client->request('GET', $url);
            self::assertSelectorExists('.cookies[hidden][data-analytics-pixel-value="123456789012345"][data-analytics-id-value=""]');
            self::assertSelectorTextContains('.cookies__text', 'de publicité (Meta');
            self::assertSelectorTextNotContains('.cookies__text', 'Google');
            self::assertStringNotContainsString('connect.facebook.net', (string) $client->getResponse()->getContent());
        }
        $client->request('GET', '/');
        self::assertSelectorExists('[data-cookies]');
        foreach (['/ventes', '/admin/connexion'] as $url) {
            $client->request('GET', $url);
            self::assertSelectorNotExists('.cookies');
        }
    }

    public function testBannerNamesBothUses(): void
    {
        $this->client('G-TEST123456', pixel: '123456789012345')->request('GET', '/');

        self::assertSelectorTextContains('.cookies__text', "de mesure d'audience (Google Analytics) et de publicité (Meta");
    }

    public function testPixelIdFormat(): void
    {
        self::assertSame('123456789012345', LiveSettings::normalizePixelId(' 123456789012345 '));
        foreach (['', '12345', 'G-AB12CD34EF', "fbq('init', '123456789012345')", '123456789012345"><script>'] as $invalide) {
            self::assertSame('', LiveSettings::normalizePixelId($invalide));
        }
    }

    private function commander(KernelBrowser $client): void
    {
        $client->submit($client->request('GET', '/')->filter('form.order')->form([
            'billet' => 'marina-28|'.self::TARIF, 'quantite' => '2',
            'prenom' => 'Awa', 'nom' => 'Diallo', 'email' => 'awa@example.com', 'telephone' => '0166617878',
        ]));
        self::assertResponseRedirects('https://checkout.fedapay.com/test', 303);
    }

    private function client(string $id, string $cle = 'ta_live_cle', string $pixel = ''): KernelBrowser
    {
        foreach (['TICKAFRICA_API_KEY' => $cle] + self::ENV as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $client = static::createClient();
        $client->disableReboot();

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([new Setting('analytics_id', $id), new Setting('pixel_id', $pixel)]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        static::getContainer()->set(LiveSettings::class, new LiveSettings($em, static::getContainer()->get(LiveConfig::class)));

        static::getContainer()->set('http_client', new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse(json_encode(['success' => true, 'data' => match (true) {
            str_ends_with($url, '/orders') => ['order_id' => 'pay-1', 'payment_url' => 'https://checkout.fedapay.com/test'],
            str_contains($url, '/orders/') => ['id' => 'pay-1', 'status' => $this->statut],
            default => ['id' => 'x', 'is_on_sale' => true, 'sellable_via_api' => true, 'products' => [['id' => self::TARIF, 'name' => 'Billet unique', 'type' => 'ticket', 'price' => '10000.00', 'available_quantity' => 50, 'is_available' => true]]],
        }]))));
        static::getContainer()->get('cache.app')->clear();

        return $client;
    }
}

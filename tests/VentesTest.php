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

final class VentesTest extends WebTestCase
{
    private const MARINA = '11111111-1111-4111-8111-111111111111';
    private const WOLO = '22222222-2222-4222-8222-222222222222';
    private const TARIF_MARINA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const TARIF_WOLO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        foreach ([
            'TICKAFRICA_API_KEY' => 'ta_test_cle',
            'TICKAFRICA_SEANCE_MARINA_28' => self::MARINA,
            'TICKAFRICA_SEANCE_WOLOGUEDE_28' => self::WOLO,
            'VENTES_CODE' => '1003',
        ] as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach (['TICKAFRICA_API_KEY', 'TICKAFRICA_SEANCE_MARINA_28', 'TICKAFRICA_SEANCE_WOLOGUEDE_28', 'VENTES_CODE'] as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        parent::tearDown();
    }

    public function testCodeRequired(): void
    {
        $client = $this->client();
        $client->request('GET', '/ventes');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="code"]');
        self::assertSelectorNotExists('.vt-hero');

        $client->submitForm('Voir les ventes', ['code' => '0000']);
        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('.adm-alert--error', 'Code incorrect');
        self::assertSame([], $this->urls); // rien n'est lu chez Tick Africa sans le code
    }

    public function testLinkPreviewIsPodlabBeninBouge(): void
    {
        $crawler = $this->client()->request('GET', '/ventes');

        // Les robots d'aperçu n'ont pas le code : c'est la page de code qu'ils lisent
        self::assertMatchesRegularExpression('#^https?://[^/]+/assets/images/og-avant-premiere(-[\w-]+)?\.png$#', $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame('Podlab × Bénin Bouge', $crawler->filter('meta[property="og:image:alt"]')->attr('content'));
        self::assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
    }

    public function testStatsUseHallCapacityWhenSet(): void
    {
        $client = $this->client(['capacite_marina-28' => '200', 'capacite_wologuede-28' => '100']);
        $client->request('GET', '/ventes');
        $client->submitForm('Voir les ventes', ['code' => '1003']);
        $crawler = $client->followRedirect();

        // 7 + 3 vendues sur 200 + 100 places dans les salles (et non 100 + 50 billets en vente)
        self::assertSame('10 / 300', preg_replace('/\s+/u', ' ', trim($crawler->filter('.vt-hero__big')->text())));
        self::assertStringContainsString('290 places restantes', $crawler->filter('.vt-hero__main')->text());
        self::assertSame(['4 %', '3 %'], $crawler->filter('.vt-seance__big')->each(fn ($n) => trim(explode(' rempli', $n->text())[0])));

        $marina = $crawler->filter('.vt-seance')->first();
        self::assertStringContainsString('Capacité salle', $marina->filter('.vt-stats')->text());
        self::assertStringContainsString('193', $marina->filter('.vt-stats')->text());
        self::assertStringContainsString('dont 93 en vente', $marina->filter('.vt-stats')->text());
        self::assertStringContainsString('Part de la salle', $marina->filter('.vt-table')->text());
    }

    public function testDashboardWithRightCode(): void
    {
        $client = $this->client();
        $client->request('GET', '/ventes');
        $client->submitForm('Voir les ventes', ['code' => '1003']);
        self::assertResponseRedirects('/ventes');
        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));

        // Marina : 7 vendues (2 commandes payées) + 93 restantes ; Wologuèdè : 3 vendues + 47 restantes
        self::assertSame('10 / 150', preg_replace('/\s+/u', ' ', trim($crawler->filter('.vt-hero__big')->text())));
        self::assertStringContainsString('140 places restantes', $crawler->filter('.vt-hero__main')->text());
        // Recette : 7 × 10 000 + 3 × 8 000
        self::assertStringContainsString('94 000 F', $crawler->filter('.vt-kpis')->text());
        // Tickets vendus par séance, juste sous le total
        self::assertSame(['7', '3'], $crawler->filter('.vt-hero .vt-par__big')->each(fn ($n) => trim($n->text())));
        self::assertSame(['7 %', '6 %'], $crawler->filter('.vt-seance__big')->each(fn ($n) => trim(explode(' rempli', $n->text())[0])));
        // Une commande en attente, une annulée : 3 payées sur 4 tentatives
        self::assertStringContainsString('75 %', $crawler->filter('.vt-kpis')->text());
        self::assertGreaterThan(0, $crawler->filter('.vt-col')->count());

        self::assertContains('GET /orders event_id='.self::MARINA, $this->urls);
    }

    /** @param array<string, string>|null $settings réglages de /admin simulés (null : base absente) */
    private function client(?array $settings = null): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        if (null !== $settings) {
            $repository = $this->createStub(EntityRepository::class);
            $repository->method('findAll')->willReturn(array_map(
                static fn (string $name, string $value): Setting => new Setting($name, $value),
                array_keys($settings),
                $settings,
            ));
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('getRepository')->willReturn($repository);
            static::getContainer()->set(LiveSettings::class, new LiveSettings($em, static::getContainer()->get(LiveConfig::class)));
        }
        $now = new \DateTimeImmutable();

        $event = fn (string $tarif, string $nom, string $prix, int $restantes): string => json_encode(['success' => true, 'data' => [
            'id' => 'x', 'name' => $nom, 'is_on_sale' => true, 'sellable_via_api' => true,
            'products' => [['id' => $tarif, 'name' => $nom, 'type' => 'ticket', 'price' => $prix, 'available_quantity' => $restantes, 'is_available' => true]],
        ]]);
        $commande = fn (string $id, string $statut, string $event, string $tarif, int $qte, int $prix, string $il_y_a, string $source = 'podlab.beninbouge.com'): array => [
            'id' => $id, 'status' => $statut, 'event_id' => $event,
            'amount' => $qte * $prix, 'created_at' => $now->modify($il_y_a)->format('c'), 'paid_at' => $now->modify($il_y_a)->format('c'),
            'items' => [['product_id' => $tarif, 'quantity' => $qte, 'unit_price' => $prix]],
            'metadata' => ['source' => $source],
        ];
        $commandes = [
            self::MARINA => [
                $commande('m1', 'validated', self::MARINA, self::TARIF_MARINA, 4, 10000, '-2 hours'),
                $commande('m2', 'validated', self::MARINA, self::TARIF_MARINA, 3, 10000, '-3 days', 'tick.africa'),
                $commande('m3', 'pending', self::MARINA, self::TARIF_MARINA, 2, 10000, '-10 minutes'),
            ],
            self::WOLO => [
                $commande('w1', 'validated', self::WOLO, self::TARIF_WOLO, 3, 8000, '-1 day'),
                $commande('w2', 'cancelled', self::WOLO, self::TARIF_WOLO, 1, 8000, '-1 day'),
            ],
        ];

        static::getContainer()->set('http_client', new MockHttpClient(function (string $method, string $url) use ($event, $commandes): MockResponse {
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $path = substr($parts['path'], \strlen('/api/partner/v1'));
            $this->urls[] = $method.' '.$path.(isset($query['event_id']) ? ' event_id='.$query['event_id'] : '');

            return match (true) {
                '/orders' === $path => new MockResponse(json_encode(['success' => true, 'data' => $commandes[$query['event_id'] ?? ''] ?? [], 'meta' => ['current_page' => 1, 'last_page' => 1]])),
                str_ends_with($path, self::MARINA) => new MockResponse($event(self::TARIF_MARINA, 'Marina', '10000.00', 93)),
                str_ends_with($path, self::WOLO) => new MockResponse($event(self::TARIF_WOLO, 'Wologuèdè', '8000.00', 47)),
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        }));
        static::getContainer()->get('cache.app')->clear();

        return $client;
    }
}

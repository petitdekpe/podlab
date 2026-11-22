<?php

namespace App\Tests;

use App\Billetterie\Billetterie;
use App\Entity\Setting;
use App\Live\LiveConfig;
use App\Live\LiveSettings;
use App\Live\SecretBox;
use App\Live\TickAfrica;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Clé API et événements des séances saisis dans /admin : ils remplacent ceux de l'environnement.
 */
final class AdminTickAfricaSettingsTest extends TestCase
{
    private const SECRET = 'un-secret-de-test-suffisamment-long';
    private const ADMIN_EVENT = '33333333-3333-4333-8333-333333333333';
    private const ENV_EVENT = '44444444-4444-4444-8444-444444444444';

    public function testSecretBoxRoundTripAndWrongSecret(): void
    {
        $box = new SecretBox(self::SECRET);
        $sealed = $box->seal('ta_live_abcdef123456');

        self::assertStringNotContainsString('ta_live_', $sealed);
        self::assertSame('ta_live_abcdef123456', $box->open($sealed));
        self::assertNull((new SecretBox('un-autre-secret-tout-aussi-long'))->open($sealed));
        self::assertFalse((new SecretBox(''))->isUsable());
    }

    public function testAdminKeyReplacesEnvironmentKey(): void
    {
        $box = new SecretBox(self::SECRET);
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = implode("\n", $options['headers']);

            return new MockResponse(json_encode(['success' => true, 'data' => []]));
        });

        $client = new TickAfrica($http, new NullLogger(), 'ta_test_cle_du_serveur', TickAfrica::DEFAULT_URL, $this->settings(['tickafrica_api_key' => $box->seal('ta_live_cle_de_admin_9876')], $box));

        self::assertSame('admin', $client->keySource());
        self::assertFalse($client->isTestMode());
        self::assertSame('ta_live_…9876', $client->maskedKey());
        $client->events();
        self::assertStringContainsString('Bearer ta_live_cle_de_admin_9876', $sent[0]);

        // Rien dans /admin : clé du serveur
        $env = new TickAfrica($http, new NullLogger(), 'ta_test_cle_du_serveur', TickAfrica::DEFAULT_URL, $this->settings([], $box));
        self::assertSame('env', $env->keySource());
        self::assertTrue($env->isTestMode());
    }

    public function testUnreadableAdminKeyFallsBackToEnvironment(): void
    {
        $sealed = (new SecretBox('ancien-secret-avant-changement'))->seal('ta_live_perdue_123456');
        $settings = $this->settings(['tickafrica_api_key' => $sealed], new SecretBox(self::SECRET));

        self::assertTrue($settings->tickafricaApiKeyUnreadable());
        $client = new TickAfrica(new MockHttpClient(), new NullLogger(), 'ta_test_cle_du_serveur', TickAfrica::DEFAULT_URL, $settings);
        self::assertSame('env', $client->keySource());
    }

    public function testAdminSeanceEventReplacesEnvironmentEvent(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse(json_encode(['success' => true, 'data' => [
                'is_on_sale' => true, 'sellable_via_api' => true,
                'products' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Billet', 'type' => 'ticket', 'price' => '10000', 'available_quantity' => 9, 'is_available' => true]],
            ]]));
        });
        $settings = $this->settings(['seance_marina-28' => self::ADMIN_EVENT]);

        $seances = (new Billetterie(new TickAfrica($http, new NullLogger(), 'ta_test_cle'), $settings, new ArrayAdapter(), new NullLogger(), '', self::ENV_EVENT, self::ENV_EVENT))->seances();

        self::assertSame(self::ADMIN_EVENT, $seances['marina-28']->eventId, 'événement saisi dans /admin');
        self::assertSame(self::ENV_EVENT, $seances['wologuede-28']->eventId, 'rien dans /admin : environnement');
        self::assertSame(9, $seances['marina-28']->placesRestantes());
    }

    /**
     * @param array<string, string> $values réglages enregistrés dans /admin
     */
    private function settings(array $values, ?SecretBox $box = null): LiveSettings
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn(array_map(
            static fn (string $name, string $value): Setting => new Setting($name, $value),
            array_keys($values),
            $values,
        ));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new LiveSettings($em, new LiveConfig('', '2026-11-22T12:30:00+01:00', '2026-11-22T16:00:00+01:00', '', ''), $box ?? new SecretBox(self::SECRET));
    }
}

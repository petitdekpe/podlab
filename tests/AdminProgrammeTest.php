<?php

namespace App\Tests;

use App\Entity\Direct;
use App\Entity\Evenement;
use App\Entity\Lieu;
use App\Entity\LiveCode;
use App\Live\SuperCode;
use App\Site\PhotosLieux;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Évènements et directs gérés dans /admin, avec une vraie base (SQLite en mémoire).
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class AdminProgrammeTest extends WebTestCase
{
    private const EVENT = '11111111-1111-4111-8111-111111111111';
    private const EVENT_DIRECT = '22222222-2222-4222-8222-222222222222';
    private const AUTRE_EVENT_DIRECT = '33333333-3333-4333-8333-333333333333';
    private const TARIF = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private KernelBrowser $client;
    private string $code;
    private EntityManagerInterface $em;
    private string $dossier;
    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        [$this->code, $hash] = SuperCode::generate();
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'TICKAFRICA_API_KEY' => 'ta_test_cle', 'LIVE_SUPERCODE_HASH' => $hash] as $nom => $valeur) {
            $this->env[$nom] = $_SERVER[$nom] ?? false;
            $_SERVER[$nom] = $_ENV[$nom] = $valeur;
        }

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        static::getContainer()->get('cache.app')->clear();
        $this->dossier = sys_get_temp_dir().'/lieux-'.bin2hex(random_bytes(4));
        static::getContainer()->set(PhotosLieux::class, new PhotosLieux($this->dossier.'/stock'));

        static::getContainer()->set('http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $corps = json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse(json_encode(['success' => true, 'data' => match (true) {
                // Chaque direct a ses billets : D1… pour le premier, D2… pour le second
                str_contains($url, '/tickets/validate') => ($corps['code'][1] ?? '') === (self::EVENT_DIRECT === $corps['event_id'] ? '1' : '2')
                    ? ['valid' => true, 'reason' => 'ok', 'ticket' => ['code' => $corps['code']]]
                    : ['valid' => false, 'reason' => 'wrong_event'],
                str_ends_with($url, '/events/'.self::EVENT) => ['id' => self::EVENT, 'name' => 'Soirée', 'is_on_sale' => true, 'sellable_via_api' => true, 'products' => [
                    ['id' => self::TARIF, 'name' => 'Billet', 'type' => 'ticket', 'price' => '5000', 'available_quantity' => 40, 'is_available' => true],
                ]],
                default => [],
            }]));
        }));
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dossier.'/{,stock/}*.*', \GLOB_BRACE) ?: []);
        @rmdir($this->dossier.'/stock');
        @rmdir($this->dossier);
        static::getContainer()->get('cache.app')->clear();
        foreach ($this->env as $nom => $valeur) {
            unset($_SERVER[$nom], $_ENV[$nom]);
            if (false !== $valeur) {
                $_SERVER[$nom] = $_ENV[$nom] = $valeur;
            }
        }
        parent::tearDown();
    }

    public function testEverythingIsBehindTheAdminLogin(): void
    {
        foreach (['/admin/evenements', '/admin/evenements/nouveau', '/admin/directs', '/admin/directs/nouveau', '/admin/lieux', '/admin/lieux/nouveau'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/admin/connexion');
        }
    }

    public function testEmptySite(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.order__soon', 'Aucune séance à venir');
        $this->client->request('GET', '/live');
        self::assertSelectorTextContains('.live__panel-title', 'Aucun direct');
        $this->client->request('GET', '/ventes');
        self::assertResponseIsSuccessful();
    }

    public function testAddEditHideAndDeleteAnEvent(): void
    {
        $this->connexion();
        $this->ajouterEvenement(['event' => self::EVENT, 'capacite' => '1 200']);

        $e = $this->evenement();
        self::assertSame('canal-olympia-2030-06-15', $e->getCle());
        self::assertSame('2030-06-15T20:00:00+01:00', $e->debut()->format('c'));
        self::assertSame([1200, '19h30', '19h55', self::EVENT], [$e->capacite, $e->portes, $e->fermeture, $e->tickafricaEventId]);

        // Il est en vente sur le site, sans toucher au code
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextContains('h1', 'Soirée spéciale');
        self::assertSame(['Sam.', '15', 'juin'], $crawler->filter('.dial__date > span')->each(fn ($n) => trim($n->text())));
        self::assertCount(1, $crawler->filter('input[name="billet"][value="canal-olympia-2030-06-15|'.self::TARIF.'"]:not([disabled])'));
        self::assertSame(['19h30', '19h55', '20h00 – 22h00'], $crawler->filter('.schedule__time')->each(fn ($n) => trim($n->text())));
        self::assertSelectorTextContains('.venue-card__name', 'Canal Olympia');
        self::assertStringContainsString('15 juin', $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertStringNotContainsString('29 novembre', $crawler->filter('.hero__cta')->text());

        // Modification : la clé (liens, commandes) ne change pas
        $this->client->request('GET', '/admin/evenements/'.$e->getId());
        $palais = new Lieu('Palais des congrès');
        $this->em->persist($palais);
        $this->em->flush();
        $this->client->request('GET', '/admin/evenements/'.$e->getId());
        $this->client->submitForm('Enregistrer', ['lieu' => (string) $palais->getId(), 'complet' => '1']);
        self::assertResponseRedirects('/admin/evenements');
        self::assertSame(['canal-olympia-2030-06-15', 'Palais des congrès'], [$this->evenement()->getCle(), $this->evenement()->lieu->nom]);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.dial.is-soldout .dial__stamp', 'Sold out');

        // Masquage puis suppression
        $this->client->request('GET', '/admin/evenements');
        $this->client->submitForm('Masquer');
        self::assertCount(0, $this->client->request('GET', '/')->filter('.dial'));
        $this->client->request('GET', '/admin/evenements');
        $this->client->submitForm('Supprimer');
        self::assertNull($this->evenement());
    }

    public function testInvalidEventIsRefusedAndKeepsTheInput(): void
    {
        $this->connexion();
        $this->client->request('GET', '/admin/evenements/nouveau');
        $this->client->submitForm('Enregistrer', ['titre' => 'Ma soirée', 'debut' => '2030-06-15T20:00', 'fin' => '2030-06-15T19:00', 'event' => 'pas-un-uuid', 'capacite' => 'beaucoup']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorCount(5, '.adm-alert--error'); // aucun lieu (2), fin, événement, capacité
        self::assertInputValueSame('titre', 'Ma soirée');
        self::assertNull($this->evenement());
    }

    public function testDuplicateKeepsTheVenueButNotTheTickets(): void
    {
        $this->connexion();
        $this->ajouterEvenement(['event' => self::EVENT]);
        $this->client->request('GET', '/admin/evenements/nouveau?copie='.$this->evenement()->getId());
        self::assertSelectorTextContains('#lieu option[selected]', 'Canal Olympia');
        self::assertInputValueSame('event', '');
        $this->client->submitForm('Enregistrer');

        $cles = array_map(fn (Evenement $e) => $e->getCle(), $this->em->getRepository(Evenement::class)->findBy([], ['id' => 'ASC']));
        self::assertSame(['canal-olympia-2030-06-15', 'canal-olympia-2030-06-15-2'], $cles);
    }

    public function testDirectIsOfferedOnItsSeance(): void
    {
        $this->connexion();
        $this->ajouterEvenement(['event' => self::EVENT]);
        $e = $this->evenement();

        // Formulaire prérempli d'après la séance
        $this->client->request('GET', '/admin/directs/nouveau?evenement='.$e->getId());
        self::assertInputValueSame('ouverture', '2030-06-15T19:30');
        $this->client->submitForm('Enregistrer', ['youtube' => 'https://youtu.be/abcdefghijk', 'event' => self::EVENT_DIRECT, 'billetterie' => 'https://tick.africa/embed/direct']);
        self::assertResponseRedirects('/admin/directs');
        $d = $this->direct();
        self::assertSame(['abcdefghijk', $e->getId()], [$d->youtube, $d->evenement->getId()]);

        $crawler = $this->client->request('GET', '/');
        self::assertCount(1, $crawler->filter('.dial__live[data-open-modal="'.$d->fenetre().'"]'));
        self::assertSelectorTextContains('dialog[data-modal-id-value="'.$d->fenetre().'"] .modal__title', 'Suivez le direct du 15 juin');
        self::assertStringNotContainsString('abcdefghijk', (string) $this->client->getResponse()->getContent());
        self::assertStringStartsWith('https://tick.africa/embed/direct', $crawler->filter('dialog iframe')->attr('data-lazy-src'));

        // Séance supprimée : le direct reste, seul
        $this->client->request('GET', '/admin/evenements');
        $this->client->submitForm('Supprimer');
        self::assertNull($this->direct()->evenement);
        $this->client->request('GET', '/');
        self::assertSelectorExists('.hero__cta [data-open-modal]');
    }

    public function testOverlappingDirectsAreRefusedUnlessHidden(): void
    {
        $this->connexion();
        $this->ajouterDirect('2030-06-15T19:00', '2030-06-15T22:00');
        self::assertResponseRedirects('/admin/directs');

        $this->ajouterDirect('2030-06-15T21:00', '2030-06-15T23:00');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.adm-alert--error', 'chevauche');

        $this->client->request('GET', '/admin/directs/nouveau');
        $form = $this->client->getCrawler()->selectButton('Enregistrer')->form(['ouverture' => '2030-06-15T21:00', 'fin' => '2030-06-15T23:00']);
        $form['publie']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/directs');

        // Et il ne peut pas être affiché ensuite
        $this->client->request('GET', '/admin/directs');
        $this->client->submitForm('Afficher');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.adm-alert--error', 'chevauche');
    }

    public function testATicketOnlyOpensItsOwnDirect(): void
    {
        $now = new \DateTimeImmutable();
        $un = new Direct($now->modify('-1 hour'), $now->modify('+1 hour'));
        $un->tickafricaEventId = self::EVENT_DIRECT;
        $un->youtube = 'abcdefghijk';
        $deux = new Direct($now->modify('+1 day'), $now->modify('+2 days'));
        $deux->tickafricaEventId = self::AUTRE_EVENT_DIRECT;
        $this->em->persist($un);
        $this->em->persist($deux);
        $this->em->flush();

        // Billet de l'autre direct : refusé
        $this->saisirCode('D2xxxx');
        self::assertSelectorExists('.live__error');
        // Le bon billet entre
        $this->saisirCode('D1xxxx');
        self::assertSelectorExists('[data-controller="live-player"]');
        self::assertSame($un->getId(), $this->em->getRepository(LiveCode::class)->findOneBy([])->getDirect()->getId());

        // Accès fermé dans l'administration, puis rouvert
        $this->em->find(Direct::class, $un->getId())->accesOuvert = false;
        $this->em->flush();
        $this->client->request('GET', '/live');
        self::assertSelectorTextContains('.live__panel-title', 'momentanément fermé');
        $un = $this->em->find(Direct::class, $un->getId());
        $deux = $this->em->find(Direct::class, $deux->getId());
        $un->accesOuvert = true;

        // Le direct suivant prend la place : l'ancien billet, même déjà validé, n'ouvre rien
        $un->setFenetre($now->modify('-10 days'), $now->modify('-9 days'));
        $deux->setFenetre($now->modify('-1 hour'), $now->modify('+1 hour'));
        $this->em->flush();
        $this->client->request('GET', '/live');
        self::assertSelectorExists('input[name="code"]');
        $this->saisirCode('D1xxxx');
        self::assertSelectorExists('.live__error');
        self::assertSelectorNotExists('[data-controller="live-player"]');
    }

    public function testADirectWithTicketsCannotBeDeleted(): void
    {
        $this->connexion();
        $this->ajouterDirect('2030-06-15T19:00', '2030-06-15T22:00');
        $this->em->persist(new LiveCode($this->direct(), 'D1xxxx', 'Awa'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/directs');
        self::assertSelectorTextContains('.adm-card', '1 billet validé');
        self::assertCount(0, $crawler->selectButton('Supprimer'));
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/directs/'.$this->direct()->getId().'/supprimer', ['_token' => $token]);
        self::assertNotNull($this->direct());

        // Billets filtrés par direct
        $crawler = $this->client->request('GET', '/admin/codes?direct='.$this->direct()->getId());
        self::assertSelectorTextContains('.adm-code', 'D1xxxx');
        $this->client->request('GET', '/admin/codes/export.csv');
        self::assertStringContainsString('D1xxxx', (string) $this->client->getResponse()->getContent());
        foreach (['/admin', '/admin/parametres'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
        }
    }

    public function testVenueWithItsPhoto(): void
    {
        $this->connexion();
        $this->client->request('GET', '/admin/lieux/nouveau');
        $this->client->submitForm('Enregistrer', [
            'nom' => 'Canal Olympia', 'detail' => 'Grande salle', 'adresse' => 'Wologuèdè, Cotonou', 'capacite' => '300',
            'photo' => $this->image('png', 2000, 1200), 'photoAlt' => 'La grande salle', 'cadrageX' => '20', 'cadrageY' => '80',
        ]);
        self::assertResponseRedirects('/admin/lieux');
        $lieu = $this->lieu();
        self::assertSame([300, 20, 80], [$lieu->capacite, $lieu->cadrageX, $lieu->cadrageY]);
        $premiere = $lieu->photo;

        // Sans évènement, le lieu n'apparaît pas sur le site
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.venue-card');

        $this->client->request('GET', '/admin/evenements/nouveau?lieu='.$lieu->getId());
        $this->client->submitForm('Enregistrer', ['debut' => '2030-06-15T20:00']);
        self::assertResponseRedirects('/admin/evenements');
        self::assertSame(300, $this->evenement()->places(), 'capacité du lieu');

        $img = $this->client->request('GET', '/')->filter('.venue-card img');
        self::assertSame(['/lieux/'.$premiere, 'La grande salle', 'object-position: 20% 80%'], [$img->attr('src'), $img->attr('alt'), $img->attr('style')]);
        self::assertSelectorTextContains('.venue__address', 'Wologuèdè, Cotonou');
        $this->client->request('GET', '/lieux/'.$premiere);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');

        // Photo remplacée : l'ancienne est effacée du serveur
        $this->client->request('GET', '/admin/lieux/'.$lieu->getId());
        $this->client->submitForm('Enregistrer', ['photo' => $this->image('jpeg', 900, 600)]);
        self::assertNotSame($premiere, $this->lieu()->photo);
        $this->client->request('GET', '/lieux/'.$premiere);
        self::assertResponseStatusCodeSame(404);

        // Photo retirée
        $this->client->request('GET', '/admin/lieux/'.$lieu->getId());
        $this->client->submitForm('Enregistrer', ['retirer' => '1']);
        self::assertSame('', $this->lieu()->photo);
        self::assertSame([], glob($this->dossier.'/stock/*'));
        $this->client->request('GET', '/');
        self::assertSelectorExists('.venue-card');
        self::assertSelectorNotExists('.venue-card img');

        // Un lieu utilisé ne se supprime pas
        $crawler = $this->client->request('GET', '/admin/lieux');
        $this->client->request('POST', '/admin/lieux/'.$lieu->getId().'/supprimer', ['_token' => $crawler->filter('input[name="_token"]')->attr('value')]);
        self::assertNotNull($this->lieu());
    }

    public function testInvalidVenueIsRefused(): void
    {
        $this->em->persist($site = new Lieu('Majestic Marina'));
        $site->photo = 'images/salle-majestic-marina.webp';
        $this->em->flush();
        $this->connexion();

        $this->client->request('GET', '/admin/lieux/nouveau');
        $this->client->submitForm('Enregistrer', ['nom' => 'Majestic Marina', 'capacite' => 'beaucoup', 'photo' => $this->image('jpeg', 900, 600)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorCount(2, '.adm-alert--error');
        self::assertSame([], glob($this->dossier.'/stock/*') ?: [], 'photo non gardée');

        // Photo refusée : le lieu et sa photo actuelle restent tels quels
        $this->client->request('GET', '/admin/lieux/'.$site->getId());
        $this->client->submitForm('Enregistrer', ['nom' => 'Autre nom', 'photo' => $this->image('jpeg', 300, 200)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.adm-alert--error', 'trop petite');
        self::assertSame(['Majestic Marina', 'images/salle-majestic-marina.webp'], [$this->lieu()->nom, $this->lieu()->photo]);
    }

    public function testVenueSectionTexts(): void
    {
        $this->ajouterLieuEtEvenement();
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('#lieu .h2', 'Où nous retrouver');

        $this->connexion();
        $this->client->request('GET', '/admin/lieux');
        $this->client->submitForm('Enregistrer les textes', ['lieux_titre' => 'Nos adresses', 'lieux_phrase' => '']);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('#lieu .h2', 'Nos adresses');
        self::assertSelectorNotExists('.venues__lead');
    }

    private function ajouterLieuEtEvenement(): void
    {
        $e = new Evenement('a', new \DateTimeImmutable('2030-06-15T20:00:00+01:00'));
        $e->lieu = new Lieu('Canal Olympia');
        $this->em->persist($e->lieu);
        $this->em->persist($e);
        $this->em->flush();
    }

    private function image(string $type, int $l, int $h): UploadedFile
    {
        @mkdir($this->dossier);
        $fichier = $this->dossier.'/'.bin2hex(random_bytes(4)).'.'.$type;
        ('image'.$type)(imagecreatetruecolor($l, $h), $fichier);

        return new UploadedFile($fichier, 'photo.'.$type, null, null, true);
    }

    private function lieu(): ?Lieu
    {
        $this->em->clear();

        return $this->em->getRepository(Lieu::class)->findOneBy([]);
    }

    private function connexion(): void
    {
        $this->client->request('GET', '/admin/connexion');
        $form = $this->client->getCrawler()->filter('form')->form(['supercode' => $this->code]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin');
    }

    /** @param array<string, string> $champs */
    private function ajouterEvenement(array $champs = []): void
    {
        $lieu = new Lieu('Canal Olympia');
        $lieu->adresse = 'Wologuèdè, Cotonou';
        $this->em->persist($lieu);
        $this->em->flush();
        $this->client->request('GET', '/admin/evenements/nouveau');
        $this->client->submitForm('Enregistrer', $champs + [
            'titre' => 'Soirée spéciale', 'debut' => '2030-06-15T20:00', 'fin' => '2030-06-15T22:00', 'portes' => '19:30', 'fermeture' => '19:55',
            'lieu' => (string) $lieu->getId(),
        ]);
        self::assertResponseRedirects('/admin/evenements');
    }

    private function ajouterDirect(string $ouverture, string $fin): void
    {
        $this->client->request('GET', '/admin/directs/nouveau');
        $this->client->submitForm('Enregistrer', ['ouverture' => $ouverture, 'fin' => $fin]);
    }

    private function saisirCode(string $code): void
    {
        $this->client->request('GET', '/live');
        $this->client->submitForm('Accéder au direct', ['code' => $code]);
        $this->client->followRedirect();
    }

    private function evenement(): ?Evenement
    {
        return $this->em->getRepository(Evenement::class)->findOneBy([]);
    }

    private function direct(): ?Direct
    {
        return $this->em->getRepository(Direct::class)->findOneBy([]);
    }
}

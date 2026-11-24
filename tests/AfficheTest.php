<?php

namespace App\Tests;

use App\Site\Affiche;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AfficheTest extends WebTestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/affiche-'.bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dossier.'/{,*/}*.*', \GLOB_BRACE) ?: []);
        @rmdir($this->dossier.'/stock');
        rmdir($this->dossier);
        parent::tearDown();
    }

    public function testReplaceThenRestore(): void
    {
        $affiche = new Affiche($this->dossier.'/stock');
        self::assertNull($affiche->personnalisee());

        self::assertNull($affiche->remplacer($this->image('png', 1800, 2250)));
        $p = $affiche->personnalisee();
        self::assertSame([1200, 1500], [$p['width'], $p['height']]); // réduite
        self::assertSame(\IMAGETYPE_JPEG, getimagesize($affiche->fichier())[2]);

        self::assertNull($affiche->remplacer($this->image('jpeg', 800, 800)));
        self::assertNotSame($p['version'], $affiche->personnalisee()['version']);

        self::assertTrue($affiche->retablir());
        self::assertNull($affiche->personnalisee());
    }

    public function testInvalidImagesAreRefusedAndTheCurrentPosterKept(): void
    {
        $affiche = new Affiche($this->dossier.'/stock');
        $affiche->remplacer($this->image('jpeg', 800, 1000));
        $avant = $affiche->personnalisee();

        file_put_contents($this->dossier.'/faux.jpg', '<?php echo 1;');
        self::assertStringContainsString('n\'est pas une image', $affiche->remplacer(new UploadedFile($this->dossier.'/faux.jpg', 'faux.jpg', 'image/jpeg', null, true)));
        self::assertStringContainsString('trop petite', $affiche->remplacer($this->image('jpeg', 300, 400)));
        self::assertStringContainsString('portrait', $affiche->remplacer($this->image('jpeg', 1500, 900)));
        self::assertStringContainsString('trop lourde', $affiche->remplacer(new UploadedFile($this->dossier.'/x.jpg', 'x.jpg', null, \UPLOAD_ERR_INI_SIZE, true)));
        self::assertSame($avant, $affiche->personnalisee());
    }

    public function testHomeShowsTheUploadedPoster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $affiche = new Affiche($this->dossier.'/stock');
        static::getContainer()->set(Affiche::class, $affiche);

        $crawler = $client->request('GET', '/');
        self::assertStringContainsString('affiche-moi-a-quatre-pattes', $crawler->filter('.poster img')->attr('src'));
        $client->request('GET', '/affiche.jpg');
        self::assertResponseStatusCodeSame(404);

        $affiche->remplacer($this->image('jpeg', 900, 1200));
        $img = $client->request('GET', '/')->filter('.poster img');
        self::assertSame('/affiche.jpg?v='.$affiche->personnalisee()['version'], $img->attr('src'));
        self::assertSelectorExists('#reservation[style="--poster-ratio: 0.75"]');
        $client->request('GET', $img->attr('src'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
    }

    public function testOnlyAdminCanReplaceThePoster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $affiche = new Affiche($this->dossier.'/stock');
        static::getContainer()->set(Affiche::class, $affiche);

        $client->request('POST', '/admin/affiche', [], ['affiche' => $this->image('jpeg', 800, 1000)]);
        self::assertResponseRedirects('/admin/connexion');
        self::assertNull($affiche->personnalisee());
    }

    private function image(string $type, int $l, int $h): UploadedFile
    {
        $fichier = $this->dossier.'/'.bin2hex(random_bytes(4)).'.'.$type;
        ('image'.$type)(imagecreatetruecolor($l, $h), $fichier);

        return new UploadedFile($fichier, 'affiche.'.$type, null, null, true);
    }
}

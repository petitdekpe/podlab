<?php

namespace App\Tests;

use App\Entity\Direct;
use App\Entity\Evenement;
use App\Entity\Lieu;
use App\Programme\Programme;
use App\Site\DateFr;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ProgrammeTest extends TestCase
{
    public function testLastReadingSurvivesADatabaseOutage(): void
    {
        $cache = new ArrayAdapter();
        $visible = new Evenement('a', new \DateTimeImmutable('2027-01-02T20:00:00+01:00'));
        $visible->lieu = new Lieu('Canal Olympia');
        $masque = new Evenement('b', new \DateTimeImmutable('2027-01-03T20:00:00+01:00'));
        $masque->publie = false;

        $programme = $this->programme([$visible, $masque], [], $cache);
        self::assertSame([$visible], $programme->evenements());
        self::assertFalse($programme->horsBase());

        $panne = $this->programme(null, null, $cache);
        self::assertSame(['a'], array_map(fn (Evenement $e) => $e->getCle(), $panne->evenements()));
        self::assertSame('Canal Olympia', $panne->evenements()[0]->lieu->nom);
        self::assertEquals($visible->debut(), $panne->evenements()[0]->debut());
        self::assertTrue($panne->horsBase());
        self::assertSame([], $panne->directsAVenir());
    }

    public function testOriginalProgrammeWhenTheDatabaseWasNeverRead(): void
    {
        self::assertSame(['marina-22', 'marina-28', 'wologuede-28'], array_map(fn (Evenement $e) => $e->getCle(), $this->programme(null, null)->evenements()));
    }

    public function testCurrentDirect(): void
    {
        $passe = $this->direct('2026-12-01 12:00', '2026-12-01 16:00');
        $masque = $this->direct('2026-12-03 12:00', '2026-12-03 16:00');
        $masque->publie = false;
        $prochain = $this->direct('2026-12-06 12:00', '2026-12-06 16:00');
        $suivant = $this->direct('2026-12-13 12:00', '2026-12-13 16:00');
        $actuel = fn (string $quand): ?Direct => $this->programme([], [$passe, $masque, $prochain, $suivant])->directActuel(new \DateTimeImmutable($quand, DateFr::zone()));

        self::assertSame($passe, $actuel('2026-11-30 10:00'));
        self::assertSame($passe, $actuel('2026-12-01 13:00'));
        self::assertSame($passe, $actuel('2026-12-01 16:30'), 'terminé depuis moins d\'une heure');
        self::assertSame($prochain, $actuel('2026-12-03 13:00'), 'le direct masqué est ignoré');
        self::assertSame($prochain, $actuel('2026-12-06 13:00'));
        self::assertSame($suivant, $actuel('2026-12-07 13:00'));
        self::assertSame($suivant, $actuel('2027-06-01 13:00'), 'le dernier, terminé');
        self::assertNull($this->programme([], [])->directActuel());
    }

    public function testDatesDoNotDependOnThePhpTimezone(): void
    {
        $avant = date_default_timezone_get();
        try {
            date_default_timezone_set('America/New_York');
            $e = new Evenement('a', new \DateTimeImmutable('2026-11-29T12:00:00+00:00'));
            self::assertSame('2026-11-29T13:00:00+01:00', $e->debut()->format('c'));
            self::assertSame('Dimanche 29 novembre', DateFr::jour($e->debut()));
            // Comme Doctrine relit une colonne sans fuseau
            self::assertSame('2026-11-29T13:00:00+01:00', DateFr::relire(new \DateTimeImmutable('2026-11-29 13:00:00'))->format('c'));
        } finally {
            date_default_timezone_set($avant);
        }
        self::assertNull(DateFr::saisie('2026-02-31T10:00'));
        self::assertNull(DateFr::saisie(''));
    }

    public function testListOfDates(): void
    {
        $liste = fn (string ...$d): string => DateFr::liste(array_map(fn (string $x) => new \DateTimeImmutable($x, DateFr::zone()), $d));

        self::assertSame('', $liste());
        self::assertSame('29 novembre', $liste('2026-11-29 13:00', '2026-11-29 18:00'));
        self::assertSame('6 et 13 décembre', $liste('2026-12-13', '2026-12-06'));
        self::assertSame('29 novembre et 6, 13 décembre', $liste('2026-11-29', '2026-12-06', '2026-12-13'));
    }

    private function direct(string $ouverture, string $fin): Direct
    {
        return new Direct(new \DateTimeImmutable($ouverture, DateFr::zone()), new \DateTimeImmutable($fin, DateFr::zone()));
    }

    /**
     * @param list<Evenement>|null $evenements null : base absente
     * @param list<Direct>|null    $directs
     */
    private function programme(?array $evenements, ?array $directs, ?ArrayAdapter $cache = null): Programme
    {
        $em = $this->createStub(EntityManagerInterface::class);
        if (null === $evenements) {
            $em->method('getRepository')->willThrowException(new \RuntimeException('pas de base'));
            $em->method('createQueryBuilder')->willThrowException(new \RuntimeException('pas de base'));
        } else {
            $repository = $this->createStub(EntityRepository::class);
            $repository->method('findBy')->willReturn($evenements);
            $em->method('getRepository')->willReturn($repository);
            $query = $this->createStub(Query::class);
            $query->method('getResult')->willReturn($directs);
            $qb = $this->createStub(QueryBuilder::class);
            foreach (['select', 'from', 'leftJoin', 'orderBy', 'addOrderBy'] as $methode) {
                $qb->method($methode)->willReturn($qb);
            }
            $qb->method('getQuery')->willReturn($query);
            $em->method('createQueryBuilder')->willReturn($qb);
        }

        return new Programme($em, $cache ?? new ArrayAdapter(), new NullLogger());
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reprend dans les nouvelles tables les trois séances et le direct qui étaient fixés
 * dans le code, avec les réglages de /admin (app_setting), sinon ceux de
 * l'environnement (TICKAFRICA_SEANCE_…, LIVE_…, TICKAFRICA_EVENT_ID).
 */
final class Version20261124090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reprise des séances et du direct existants';
    }

    public function up(Schema $schema): void
    {
        $reglages = $this->connection->fetchAllKeyValue('SELECT name, setting_value FROM app_setting');
        $env = static fn (string $nom): string => trim((string) ($_ENV[$nom] ?? $_SERVER[$nom] ?? ''));
        $lu = static fn (string $nom, string $variable = ''): string => trim((string) ($reglages[$nom] ?? '')) ?: ('' !== $variable ? $env($variable) : '');
        $uuid = static fn (string $v): string => preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', strtolower(trim($v))) ? strtolower(trim($v)) : '';

        $marina = ['Majestic Marina', 'Sofitel Cotonou Marina Hotel', 'Boulevard de la Marina, Cotonou', 'Sofitel Cotonou Marina Hotel, Boulevard de la Marina, Cotonou'];
        $wologuede = ['Majestic Wologuèdè', 'Majestic Cinéma', 'Wologuèdè, Cotonou', 'Majestic Cinéma Wologuèdè, Cotonou'];
        foreach ([
            ['marina-22', '2026-11-22', $marina, 'TICKAFRICA_SEANCE_MARINA_22'],
            ['marina-28', '2026-11-29', $marina, 'TICKAFRICA_SEANCE_MARINA_28'],
            ['wologuede-28', '2026-11-29', $wologuede, 'TICKAFRICA_SEANCE_WOLOGUEDE_28'],
        ] as [$cle, $jour, $salle, $variable]) {
            [$event, $tarifs] = array_pad(explode(':', $lu('seance_'.$cle, $variable), 2), 2, '');
            $capacite = $lu('capacite_'.$cle);
            $this->addSql(
                'INSERT INTO evenement (cle, titre, debut, fin, portes, fermeture, salle_nom, salle_detail, adresse, maps, tickafrica_event_id, tickafrica_tarifs, capacite, complet, publie, bilan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $cle, 'Moi, à quatre pattes ?!', $jour.' 13:00:00', $jour.' 15:00:00', '12h30', '12h55', ...$salle,
                    $uuid($event), implode(',', array_filter(array_map($uuid, explode(',', $tarifs)))),
                    ctype_digit($capacite) && (int) $capacite > 0 ? (int) $capacite : null,
                    'marina-22' === $cle && '0' !== ($reglages['soldout'] ?? null), true, 'marina-22' !== $cle,
                ],
                [13 => Types::BOOLEAN, 14 => Types::BOOLEAN, 15 => Types::BOOLEAN],
            );
        }

        $local = static fn (string $v, string $defaut): string => (new \DateTimeImmutable($v ?: $defaut))->setTimezone(new \DateTimeZone('Africa/Porto-Novo'))->format('Y-m-d H:i:s');
        $video = trim((string) ($reglages['youtube'] ?? $env('LIVE_YOUTUBE_ID')));
        $video = preg_match('~^(?:.*(?:[?&]v=|youtu\.be/|/live/|/embed/))?([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~', $video, $m) ? $m[1] : '';
        $this->addSql(
            'INSERT INTO live_direct (evenement_id, titre, youtube, ouverture, fin, tickafrica_event_id, billetterie_url, acces_ouvert, filigrane, publie) VALUES ((SELECT id FROM evenement WHERE cle = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'marina-22', $lu('title') ?: 'Edgar-Yves : Moi, à quatre pattes ?!', $video,
                $local($lu('opens_at', 'LIVE_OPENS_AT'), '2026-11-22T12:30:00+01:00'),
                $local($lu('ends_at', 'LIVE_ENDS_AT'), '2026-11-22T16:00:00+01:00'),
                $uuid($lu('tickafrica_event_id')) ?: $uuid($env('TICKAFRICA_EVENT_ID')),
                mb_substr($lu('live_ticket_url', 'LIVE_TICKETS_URL'), 0, 500),
                '0' !== ($reglages['enabled'] ?? null), '0' !== ($reglages['watermark'] ?? null), true,
            ],
            [7 => Types::BOOLEAN, 8 => Types::BOOLEAN, 9 => Types::BOOLEAN],
        );
        // Les billets déjà validés l'ont été pour ce direct
        $this->addSql('UPDATE live_code SET direct_id = (SELECT MIN(id) FROM live_direct) WHERE direct_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE live_code SET direct_id = NULL');
        $this->addSql('DELETE FROM live_direct');
        $this->addSql('DELETE FROM evenement');
    }
}

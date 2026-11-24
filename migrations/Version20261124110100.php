<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un lieu par salle saisie sur les évènements (avec la photo du Majestic Marina livrée
 * avec le site), puis retrait des anciennes colonnes.
 */
final class Version20261124110100 extends AbstractMigration
{
    private const COLONNES = ['salle_nom' => 120, 'salle_detail' => 160, 'adresse' => 200, 'maps' => 255];

    public function getDescription(): string
    {
        return 'Reprise des lieux des évènements';
    }

    public function up(Schema $schema): void
    {
        // Ces requêtes passent avant le retrait des colonnes
        $this->addSql("INSERT INTO lieu (nom, detail, adresse, maps, capacite, photo, photo_alt, cadrage_x, cadrage_y)
            SELECT salle_nom, MAX(salle_detail), MAX(adresse), MAX(maps), MAX(capacite), '', '', 50, 50 FROM evenement GROUP BY salle_nom");
        $this->addSql(
            'UPDATE lieu SET photo = ?, photo_alt = ?, cadrage_x = 30, cadrage_y = 70 WHERE nom = ?',
            ['images/salle-majestic-marina.webp', 'Fauteuils en velours rouge avec tablettes, salle du Majestic Marina', 'Majestic Marina'],
        );
        $this->addSql('UPDATE evenement SET lieu_id = (SELECT lieu.id FROM lieu WHERE lieu.nom = evenement.salle_nom)');
        // Capacité égale à celle du lieu : elle la suivra désormais
        $this->addSql('UPDATE evenement SET capacite = NULL WHERE capacite = (SELECT lieu.capacite FROM lieu WHERE lieu.id = evenement.lieu_id)');

        $e = $schema->getTable('evenement');
        $e->modifyColumn('lieu_id', ['notnull' => true]);
        foreach (array_keys(self::COLONNES) as $colonne) {
            $e->dropColumn($colonne);
        }
    }

    public function down(Schema $schema): void
    {
        $e = $schema->getTable('evenement');
        $e->modifyColumn('lieu_id', ['notnull' => false]);
        foreach (self::COLONNES as $colonne => $longueur) {
            $e->addColumn($colonne, Types::STRING, ['length' => $longueur, 'default' => '']);
        }
    }
}

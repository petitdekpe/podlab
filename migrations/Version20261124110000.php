<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lieux gérés dans /admin. Les salles saisies sur les évènements sont reprises par la migration suivante.
 */
final class Version20261124110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table lieu, evenement.lieu_id';
    }

    public function up(Schema $schema): void
    {
        $l = $schema->createTable('lieu');
        $l->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $l->addColumn('nom', Types::STRING, ['length' => 120]);
        $l->addColumn('detail', Types::STRING, ['length' => 160]);
        $l->addColumn('adresse', Types::STRING, ['length' => 200]);
        $l->addColumn('maps', Types::STRING, ['length' => 255]);
        $l->addColumn('capacite', Types::INTEGER, ['notnull' => false]);
        $l->addColumn('photo', Types::STRING, ['length' => 120]);
        $l->addColumn('photo_alt', Types::STRING, ['length' => 200]);
        $l->addColumn('cadrage_x', Types::INTEGER);
        $l->addColumn('cadrage_y', Types::INTEGER);
        $l->setPrimaryKey(['id']);
        $l->addUniqueIndex(['nom'], 'uniq_lieu_nom');

        $e = $schema->getTable('evenement');
        $e->addColumn('lieu_id', Types::INTEGER, ['notnull' => false]);
        $e->addIndex(['lieu_id']);
        $e->addForeignKeyConstraint('lieu', ['lieu_id'], ['id']);
    }

    public function down(Schema $schema): void
    {
        foreach (['salle_nom' => 'nom', 'salle_detail' => 'detail', 'adresse' => 'adresse', 'maps' => 'maps'] as $colonne => $source) {
            $this->addSql("UPDATE evenement SET $colonne = (SELECT lieu.$source FROM lieu WHERE lieu.id = evenement.lieu_id) WHERE lieu_id IS NOT NULL");
        }
        $this->addSql('UPDATE evenement SET capacite = (SELECT lieu.capacite FROM lieu WHERE lieu.id = evenement.lieu_id) WHERE capacite IS NULL');

        $e = $schema->getTable('evenement');
        foreach ($e->getForeignKeys() as $cle) {
            $e->removeForeignKey($cle->getName());
        }
        foreach ($e->getIndexes() as $index) {
            if (['lieu_id'] === $index->getColumns()) {
                $e->dropIndex($index->getName());
            }
        }
        $e->dropColumn('lieu_id');
        $schema->dropTable('lieu');
    }
}

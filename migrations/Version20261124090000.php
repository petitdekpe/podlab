<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Évènements et directs gérés dans /admin. Les données existantes sont reprises par la migration suivante.
 */
final class Version20261124090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables evenement et live_direct, live_code.direct_id';
    }

    public function up(Schema $schema): void
    {
        $e = $schema->createTable('evenement');
        $e->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $e->addColumn('cle', Types::STRING, ['length' => 64]);
        $e->addColumn('titre', Types::STRING, ['length' => 120]);
        $e->addColumn('debut', Types::DATETIME_IMMUTABLE);
        $e->addColumn('fin', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $e->addColumn('portes', Types::STRING, ['length' => 5]);
        $e->addColumn('fermeture', Types::STRING, ['length' => 5]);
        $e->addColumn('salle_nom', Types::STRING, ['length' => 120]);
        $e->addColumn('salle_detail', Types::STRING, ['length' => 160]);
        $e->addColumn('adresse', Types::STRING, ['length' => 200]);
        $e->addColumn('maps', Types::STRING, ['length' => 255]);
        $e->addColumn('tickafrica_event_id', Types::STRING, ['length' => 36]);
        $e->addColumn('tickafrica_tarifs', Types::TEXT);
        $e->addColumn('capacite', Types::INTEGER, ['notnull' => false]);
        $e->addColumn('complet', Types::BOOLEAN);
        $e->addColumn('publie', Types::BOOLEAN);
        $e->addColumn('bilan', Types::BOOLEAN);
        $e->setPrimaryKey(['id']);
        $e->addUniqueIndex(['cle'], 'uniq_evenement_cle');

        $d = $schema->createTable('live_direct');
        $d->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $d->addColumn('evenement_id', Types::INTEGER, ['notnull' => false]);
        $d->addColumn('titre', Types::STRING, ['length' => 120]);
        $d->addColumn('youtube', Types::STRING, ['length' => 11]);
        $d->addColumn('ouverture', Types::DATETIME_IMMUTABLE);
        $d->addColumn('fin', Types::DATETIME_IMMUTABLE);
        $d->addColumn('tickafrica_event_id', Types::STRING, ['length' => 36]);
        $d->addColumn('billetterie_url', Types::STRING, ['length' => 500]);
        $d->addColumn('acces_ouvert', Types::BOOLEAN);
        $d->addColumn('filigrane', Types::BOOLEAN);
        $d->addColumn('publie', Types::BOOLEAN);
        $d->setPrimaryKey(['id']);
        $d->addIndex(['evenement_id']);
        $d->addForeignKeyConstraint('evenement', ['evenement_id'], ['id'], ['onDelete' => 'SET NULL']);

        // Un billet n'ouvre que le direct pour lequel il a été validé
        $c = $schema->getTable('live_code');
        $c->addColumn('direct_id', Types::INTEGER, ['notnull' => false]);
        $c->dropIndex('uniq_live_code_code');
        $c->addUniqueIndex(['direct_id', 'code'], 'uniq_live_code_direct_code');
        $c->addIndex(['direct_id']);
        $c->addForeignKeyConstraint('live_direct', ['direct_id'], ['id']);
    }

    public function down(Schema $schema): void
    {
        $c = $schema->getTable('live_code');
        foreach ($c->getForeignKeys() as $cle) {
            $c->removeForeignKey($cle->getName());
        }
        foreach ($c->getIndexes() as $index) {
            if ('direct_id' === $index->getColumns()[0]) {
                $c->dropIndex($index->getName());
            }
        }
        $c->dropColumn('direct_id');
        $c->addUniqueIndex(['code'], 'uniq_live_code_code');
        $schema->dropTable('live_direct');
        $schema->dropTable('evenement');
    }
}

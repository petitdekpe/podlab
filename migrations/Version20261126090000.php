<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Habillage publicitaire en L des directs : activé par défaut, passages automatiques.
 */
final class Version20261126090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'live_direct : habillage, habillage_heures, habillage_duree';
    }

    public function up(Schema $schema): void
    {
        $d = $schema->getTable('live_direct');
        $d->addColumn('habillage', Types::BOOLEAN, ['default' => true]);
        $d->addColumn('habillage_heures', Types::STRING, ['length' => 17, 'default' => '']);
        $d->addColumn('habillage_duree', Types::INTEGER, ['default' => 30]);
    }

    public function down(Schema $schema): void
    {
        $d = $schema->getTable('live_direct');
        $d->dropColumn('habillage');
        $d->dropColumn('habillage_heures');
        $d->dropColumn('habillage_duree');
    }
}

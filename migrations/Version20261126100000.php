<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vidéo enregistrée diffusée comme un direct. Les directs existants restent des directs.
 */
final class Version20261126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'live_direct : enregistree, lancement_heure';
    }

    public function up(Schema $schema): void
    {
        $d = $schema->getTable('live_direct');
        $d->addColumn('enregistree', Types::BOOLEAN, ['default' => false]);
        $d->addColumn('lancement_heure', Types::STRING, ['length' => 5, 'default' => '']);
    }

    public function down(Schema $schema): void
    {
        $d = $schema->getTable('live_direct');
        $d->dropColumn('enregistree');
        $d->dropColumn('lancement_heure');
    }
}

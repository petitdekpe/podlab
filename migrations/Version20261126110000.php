<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les passages de l'habillage sont entièrement automatiques : plus d'heures saisies.
 */
final class Version20261126110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'live_direct : retrait de habillage_heures';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('live_direct')->dropColumn('habillage_heures');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('live_direct')->addColumn('habillage_heures', Types::STRING, ['length' => 17, 'default' => '']);
    }
}

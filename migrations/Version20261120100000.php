<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Billets Tick Africa : la colonne code accueille leurs codes (jusqu'à 64 caractères).
 */
final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'live_code.code : 64 caractères (codes Tick Africa)';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('live_code')->modifyColumn('code', ['length' => 64]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('live_code')->modifyColumn('code', ['length' => 16]);
    }
}

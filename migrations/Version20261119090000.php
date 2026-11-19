<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Direct protégé : codes d'accès (un par billet) et paramètres de l'espace d'administration.
 *
 * Écrite avec l'API Schema pour fonctionner sur PostgreSQL comme sur SQLite.
 */
final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables live_code et app_setting';
    }

    public function up(Schema $schema): void
    {
        $code = $schema->createTable('live_code');
        $code->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $code->addColumn('code', Types::STRING, ['length' => 16]);
        $code->addColumn('label', Types::STRING, ['length' => 160, 'notnull' => false]);
        $code->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $code->addColumn('first_used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $code->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $code->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $code->addColumn('claim_token', Types::STRING, ['length' => 64, 'notnull' => false]);
        $code->setPrimaryKey(['id']);
        $code->addUniqueIndex(['code'], 'uniq_live_code_code');

        $setting = $schema->createTable('app_setting');
        $setting->addColumn('name', Types::STRING, ['length' => 64]);
        $setting->addColumn('setting_value', Types::TEXT, ['notnull' => false]);
        $setting->setPrimaryKey(['name']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('app_setting');
        $schema->dropTable('live_code');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250704210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create vault_settings table for optional database-backed runtime configuration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vault_settings (
            scope VARCHAR(64) NOT NULL,
            config_values JSON NOT NULL,
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(scope)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vault_settings');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250705120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encrypted encryption_key column to vault_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vault_settings ADD encryption_key LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vault_settings DROP encryption_key');
    }
}

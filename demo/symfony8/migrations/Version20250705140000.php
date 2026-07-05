<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250705140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vault_extension_tokens table for browser extension Bearer auth';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vault_extension_tokens (id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_VAULT_EXT_TOKEN_HASH (token_hash), INDEX vault_extension_tokens_hash_idx (token_hash), INDEX vault_extension_tokens_expires_idx (expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE vault_extension_tokens ADD CONSTRAINT FK_VAULT_EXT_TOKEN_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vault_extension_tokens DROP FOREIGN KEY FK_VAULT_EXT_TOKEN_USER');
        $this->addSql('DROP TABLE vault_extension_tokens');
    }
}

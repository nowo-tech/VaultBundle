<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250704000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create vault_items, vault_folders, vault_grants tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vault_folders (
            id CHAR(36) NOT NULL,
            name VARCHAR(255) NOT NULL,
            creator_id INT NOT NULL,
            parent_id CHAR(36) DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_VAULT_FOLDER_CREATOR (creator_id),
            INDEX IDX_VAULT_FOLDER_PARENT (parent_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE vault_items (
            id CHAR(36) NOT NULL,
            item_type VARCHAR(32) NOT NULL,
            title VARCHAR(255) NOT NULL,
            creator_id INT NOT NULL,
            folder_id CHAR(36) DEFAULT NULL,
            ciphertext LONGTEXT NOT NULL,
            deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX vault_items_type_idx (item_type),
            INDEX vault_items_deleted_idx (deleted_at),
            INDEX IDX_VAULT_ITEM_CREATOR (creator_id),
            INDEX IDX_VAULT_ITEM_FOLDER (folder_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE vault_grants (
            id CHAR(36) NOT NULL,
            resource_type VARCHAR(16) NOT NULL,
            resource_id CHAR(36) NOT NULL,
            grantee_type VARCHAR(16) NOT NULL,
            grantee_id VARCHAR(128) NOT NULL,
            permission VARCHAR(16) NOT NULL,
            created_by_id INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX vault_grants_unique (resource_type, resource_id, grantee_type, grantee_id),
            INDEX IDX_VAULT_GRANT_CREATOR (created_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE vault_folders ADD CONSTRAINT FK_VAULT_FOLDER_CREATOR FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vault_folders ADD CONSTRAINT FK_VAULT_FOLDER_PARENT FOREIGN KEY (parent_id) REFERENCES vault_folders (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vault_items ADD CONSTRAINT FK_VAULT_ITEM_CREATOR FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vault_items ADD CONSTRAINT FK_VAULT_ITEM_FOLDER FOREIGN KEY (folder_id) REFERENCES vault_folders (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vault_grants ADD CONSTRAINT FK_VAULT_GRANT_CREATOR FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vault_items DROP FOREIGN KEY FK_VAULT_ITEM_FOLDER');
        $this->addSql('ALTER TABLE vault_items DROP FOREIGN KEY FK_VAULT_ITEM_CREATOR');
        $this->addSql('ALTER TABLE vault_folders DROP FOREIGN KEY FK_VAULT_FOLDER_PARENT');
        $this->addSql('ALTER TABLE vault_folders DROP FOREIGN KEY FK_VAULT_FOLDER_CREATOR');
        $this->addSql('ALTER TABLE vault_grants DROP FOREIGN KEY FK_VAULT_GRANT_CREATOR');
        $this->addSql('DROP TABLE vault_grants');
        $this->addSql('DROP TABLE vault_items');
        $this->addSql('DROP TABLE vault_folders');
    }
}

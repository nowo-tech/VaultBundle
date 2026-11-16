<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116164555 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bring the demo schema up to the current bundle entities (MySQL; foreign key checks are off while the id column types are aligned).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql('ALTER TABLE vault_extension_tokens RENAME INDEX uniq_vault_ext_token_hash TO UNIQ_85681484B3BC57DA');
        $this->addSql('ALTER TABLE vault_extension_tokens RENAME INDEX fk_vault_ext_token_user TO IDX_85681484A76ED395');
        $this->addSql('ALTER TABLE vault_folders ADD owner_scope VARCHAR(128) DEFAULT NULL, ADD created_by_id INT DEFAULT NULL, CHANGE id id VARCHAR(36) NOT NULL, CHANGE creator_id creator_id INT DEFAULT NULL, CHANGE parent_id parent_id VARCHAR(36) DEFAULT NULL, CHANGE deleted_at deleted_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE vault_folders ADD CONSTRAINT FK_A02EA031B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX vault_folders_owner_scope_idx ON vault_folders (owner_scope, deleted_at)');
        $this->addSql('CREATE INDEX IDX_A02EA031B03A8386 ON vault_folders (created_by_id)');
        $this->addSql('ALTER TABLE vault_folders RENAME INDEX idx_vault_folder_parent TO IDX_A02EA031727ACA70');
        $this->addSql('ALTER TABLE vault_folders RENAME INDEX idx_vault_folder_creator TO IDX_A02EA03161220EA6');
        $this->addSql('ALTER TABLE vault_grants DROP FOREIGN KEY `FK_VAULT_GRANT_CREATOR`');
        $this->addSql('ALTER TABLE vault_grants ADD expires_at DATETIME DEFAULT NULL, CHANGE id id VARCHAR(36) NOT NULL, CHANGE resource_id resource_id VARCHAR(36) NOT NULL, CHANGE created_by_id created_by_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE vault_grants ADD CONSTRAINT FK_A327F7AEB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX vault_grants_expires_idx ON vault_grants (expires_at)');
        $this->addSql('CREATE INDEX vault_grants_grantee_idx ON vault_grants (grantee_type, grantee_id)');
        $this->addSql('ALTER TABLE vault_grants RENAME INDEX idx_vault_grant_creator TO IDX_A327F7AEB03A8386');
        $this->addSql('ALTER TABLE vault_items ADD secret_changed_at DATETIME DEFAULT NULL, ADD catalogue_hidden TINYINT DEFAULT 0 NOT NULL, ADD owner_scope VARCHAR(128) DEFAULT NULL, ADD created_by_id INT DEFAULT NULL, CHANGE id id VARCHAR(36) NOT NULL, CHANGE creator_id creator_id INT DEFAULT NULL, CHANGE folder_id folder_id VARCHAR(36) DEFAULT NULL, CHANGE deleted_at deleted_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE vault_items ADD CONSTRAINT FK_BC862536B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX vault_items_owner_scope_idx ON vault_items (owner_scope, deleted_at, updated_at)');
        $this->addSql('CREATE INDEX vault_items_created_by_idx ON vault_items (created_by_id)');
        $this->addSql('ALTER TABLE vault_items RENAME INDEX idx_vault_item_folder TO IDX_BC862536162CB942');
        $this->addSql('ALTER TABLE vault_items RENAME INDEX idx_vault_item_creator TO IDX_BC86253661220EA6');
        $this->addSql('ALTER TABLE vault_item_tag CHANGE item_id item_id VARCHAR(36) NOT NULL, CHANGE tag_id tag_id VARCHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE vault_item_tag RENAME INDEX idx_vault_item_tag_item TO IDX_25A3C869126F525E');
        $this->addSql('ALTER TABLE vault_item_tag RENAME INDEX idx_vault_item_tag_tag TO IDX_25A3C869BAD26311');
        $this->addSql('ALTER TABLE vault_settings CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE vault_tags ADD owner_scope VARCHAR(128) DEFAULT NULL, CHANGE id id VARCHAR(36) NOT NULL, CHANGE creator_id creator_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX vault_tags_scope_name_unique ON vault_tags (owner_scope, name)');
        $this->addSql('ALTER TABLE vault_tags RENAME INDEX idx_vault_tag_creator TO IDX_9F6BEDDC61220EA6');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql('ALTER TABLE vault_extension_tokens RENAME INDEX idx_85681484a76ed395 TO FK_VAULT_EXT_TOKEN_USER');
        $this->addSql('ALTER TABLE vault_extension_tokens RENAME INDEX uniq_85681484b3bc57da TO UNIQ_VAULT_EXT_TOKEN_HASH');
        $this->addSql('ALTER TABLE vault_folders DROP FOREIGN KEY FK_A02EA031B03A8386');
        $this->addSql('DROP INDEX vault_folders_owner_scope_idx ON vault_folders');
        $this->addSql('DROP INDEX IDX_A02EA031B03A8386 ON vault_folders');
        $this->addSql('ALTER TABLE vault_folders DROP owner_scope, DROP created_by_id, CHANGE id id CHAR(36) NOT NULL, CHANGE deleted_at deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE parent_id parent_id CHAR(36) DEFAULT NULL, CHANGE creator_id creator_id INT NOT NULL');
        $this->addSql('ALTER TABLE vault_folders RENAME INDEX idx_a02ea03161220ea6 TO IDX_VAULT_FOLDER_CREATOR');
        $this->addSql('ALTER TABLE vault_folders RENAME INDEX idx_a02ea031727aca70 TO IDX_VAULT_FOLDER_PARENT');
        $this->addSql('ALTER TABLE vault_grants DROP FOREIGN KEY FK_A327F7AEB03A8386');
        $this->addSql('DROP INDEX vault_grants_expires_idx ON vault_grants');
        $this->addSql('DROP INDEX vault_grants_grantee_idx ON vault_grants');
        $this->addSql('ALTER TABLE vault_grants DROP expires_at, CHANGE id id CHAR(36) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE resource_id resource_id CHAR(36) NOT NULL, CHANGE created_by_id created_by_id INT NOT NULL');
        $this->addSql('ALTER TABLE vault_grants ADD CONSTRAINT `FK_VAULT_GRANT_CREATOR` FOREIGN KEY (created_by_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vault_grants RENAME INDEX idx_a327f7aeb03a8386 TO IDX_VAULT_GRANT_CREATOR');
        $this->addSql('ALTER TABLE vault_item_tag CHANGE item_id item_id CHAR(36) NOT NULL, CHANGE tag_id tag_id CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE vault_item_tag RENAME INDEX idx_25a3c869126f525e TO IDX_VAULT_ITEM_TAG_ITEM');
        $this->addSql('ALTER TABLE vault_item_tag RENAME INDEX idx_25a3c869bad26311 TO IDX_VAULT_ITEM_TAG_TAG');
        $this->addSql('ALTER TABLE vault_items DROP FOREIGN KEY FK_BC862536B03A8386');
        $this->addSql('DROP INDEX vault_items_owner_scope_idx ON vault_items');
        $this->addSql('DROP INDEX vault_items_created_by_idx ON vault_items');
        $this->addSql('ALTER TABLE vault_items DROP secret_changed_at, DROP catalogue_hidden, DROP owner_scope, DROP created_by_id, CHANGE id id CHAR(36) NOT NULL, CHANGE deleted_at deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE folder_id folder_id CHAR(36) DEFAULT NULL, CHANGE creator_id creator_id INT NOT NULL');
        $this->addSql('ALTER TABLE vault_items RENAME INDEX idx_bc86253661220ea6 TO IDX_VAULT_ITEM_CREATOR');
        $this->addSql('ALTER TABLE vault_items RENAME INDEX idx_bc862536162cb942 TO IDX_VAULT_ITEM_FOLDER');
        $this->addSql('ALTER TABLE vault_settings CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('DROP INDEX vault_tags_scope_name_unique ON vault_tags');
        $this->addSql('ALTER TABLE vault_tags DROP owner_scope, CHANGE id id CHAR(36) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE creator_id creator_id INT NOT NULL');
        $this->addSql('ALTER TABLE vault_tags RENAME INDEX idx_9f6beddc61220ea6 TO IDX_VAULT_TAG_CREATOR');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250704200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create vault_tags and vault_item_tag tables for item tagging';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vault_tags (
            id CHAR(36) NOT NULL,
            name VARCHAR(64) NOT NULL,
            creator_id INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX vault_tags_creator_name_unique (creator_id, name),
            INDEX IDX_VAULT_TAG_CREATOR (creator_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE vault_item_tag (
            item_id CHAR(36) NOT NULL,
            tag_id CHAR(36) NOT NULL,
            INDEX IDX_VAULT_ITEM_TAG_ITEM (item_id),
            INDEX IDX_VAULT_ITEM_TAG_TAG (tag_id),
            PRIMARY KEY(item_id, tag_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE vault_tags ADD CONSTRAINT FK_VAULT_TAG_CREATOR FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vault_item_tag ADD CONSTRAINT FK_VAULT_ITEM_TAG_ITEM FOREIGN KEY (item_id) REFERENCES vault_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vault_item_tag ADD CONSTRAINT FK_VAULT_ITEM_TAG_TAG FOREIGN KEY (tag_id) REFERENCES vault_tags (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vault_item_tag DROP FOREIGN KEY FK_VAULT_ITEM_TAG_TAG');
        $this->addSql('ALTER TABLE vault_item_tag DROP FOREIGN KEY FK_VAULT_ITEM_TAG_ITEM');
        $this->addSql('ALTER TABLE vault_tags DROP FOREIGN KEY FK_VAULT_TAG_CREATOR');
        $this->addSql('DROP TABLE vault_item_tag');
        $this->addSql('DROP TABLE vault_tags');
    }
}

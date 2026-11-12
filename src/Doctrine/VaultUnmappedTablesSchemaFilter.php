<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\Name;

use function array_map;
use function end;
use function explode;
use function in_array;
use function strtolower;
use function trim;

/**
 * DBAL schema asset filter of the vault connection (1.18.0, `database.conditional_mapping: true`): hides the tables of
 * the features that are off (`{prefix}_settings`, `{prefix}_extension_tokens`) from the schema tools. An existing table
 * of a disabled feature is therefore never dropped by `doctrine:schema:update` or a generated migration, and
 * `doctrine:schema:validate` stays in sync; nothing creates it either. Turning the feature back on maps the table again.
 */
final readonly class VaultUnmappedTablesSchemaFilter
{
    /** @var list<string> */
    private array $tables;

    /**
     * @param list<string> $tables table names hidden from the schema tools
     */
    public function __construct(array $tables)
    {
        $this->tables = array_map(strtolower(...), $tables);
    }

    /**
     * True when the asset stays visible to the schema tools.
     *
     * @param AbstractAsset<Name>|string $asset asset name (DBAL 4) or asset (DBAL 3)
     */
    public function __invoke(string|AbstractAsset $asset): bool
    {
        $name = $asset instanceof AbstractAsset ? $asset->getName() : $asset;
        // "schema.table" and quoted identifiers ("vault_settings", `vault_settings`) compare by the bare table name.
        $parts = explode('.', $name);
        $table = strtolower(trim(end($parts), '"`[]'));

        return !in_array($table, $this->tables, true);
    }
}

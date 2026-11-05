<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use Nowo\VaultBundle\Service\VaultPersonalDataEraser;

use function array_sum;

/**
 * Rows touched by {@see VaultPersonalDataEraser::erase()} (1.16.0, contract §8.2). A second
 * run on the same user gives zero everywhere.
 */
final readonly class VaultErasureResult
{
    /**
     * @param int $grantsReceived grants to the user, deleted (step 1)
     * @param int $grantsOnPersonal grants on the user's personal items and folders, deleted (step 2)
     * @param int $grantsCreatedAnonymised remaining grants created by the user, `created_by_id` set to NULL (step 3)
     * @param int $itemTags `vault_item_tag` rows of personal items, deleted (step 4)
     * @param int $items personal items (trash included), deleted (step 4)
     * @param int $folders personal folders, deleted (step 5)
     * @param int $tags personal tags, deleted (step 5)
     * @param int $scopeItemsAnonymised scope items created by the user, `created_by_id` set to NULL (step 6)
     * @param int $scopeFoldersAnonymised scope folders created by the user, `created_by_id` set to NULL (step 6)
     * @param int $extensionTokens browser extension tokens, deleted (step 7)
     */
    public function __construct(
        public int $grantsReceived = 0,
        public int $grantsOnPersonal = 0,
        public int $grantsCreatedAnonymised = 0,
        public int $itemTags = 0,
        public int $items = 0,
        public int $folders = 0,
        public int $tags = 0,
        public int $scopeItemsAnonymised = 0,
        public int $scopeFoldersAnonymised = 0,
        public int $extensionTokens = 0,
    ) {
    }

    /**
     * Counts keyed by step name (event payload and logs).
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'grants_received'           => $this->grantsReceived,
            'grants_on_personal'        => $this->grantsOnPersonal,
            'grants_created_anonymised' => $this->grantsCreatedAnonymised,
            'item_tags'                 => $this->itemTags,
            'items'                     => $this->items,
            'folders'                   => $this->folders,
            'tags'                      => $this->tags,
            'scope_items_anonymised'    => $this->scopeItemsAnonymised,
            'scope_folders_anonymised'  => $this->scopeFoldersAnonymised,
            'extension_tokens'          => $this->extensionTokens,
        ];
    }

    /**
     * Total rows touched.
     */
    public function total(): int
    {
        return array_sum($this->toArray());
    }
}

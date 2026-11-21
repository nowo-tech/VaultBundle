<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use InvalidArgumentException;
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\View\VaultItemPage;
use Nowo\VaultBundle\View\VaultItemView;

/**
 * Read port over the vault items for a host (2.1.0, DDD-host checklist B7, REQ-ARCH-001 rules 1 and 3): list, count and
 * look up items by owner key (a user id or a scope key) and ids, so a host never writes DQL over the vault's entities.
 * Implemented by {@see VaultItemQuery} on the vault's private entity manager.
 *
 * It is a **system read**: it checks no access. The views carry metadata only (no payload value, no ciphertext, no
 * secret hash). A host that shows the result to a user decides with its own rules or with
 * {@see VaultAccessCheckInterface} (by id) first.
 */
interface VaultItemQueryInterface
{
    /**
     * One page of the items that match the criteria, in the criteria's order, and the total.
     */
    public function find(VaultItemCriteria $criteria): VaultItemPage;

    /**
     * How many items match the criteria (the page is ignored).
     */
    public function count(VaultItemCriteria $criteria): int;

    /**
     * The item with exactly this id (trashed items included), or null.
     */
    public function findById(string $itemId): ?VaultItemView;

    /**
     * The items with these ids (trashed items included), in the order of the ids; unknown ids are left out.
     *
     * @param list<string> $itemIds at most {@see VaultItemCriteria::MAX_LIMIT}
     *
     * @throws InvalidArgumentException with more ids than that
     *
     * @return list<VaultItemView>
     */
    public function findByIds(array $itemIds): array;
}

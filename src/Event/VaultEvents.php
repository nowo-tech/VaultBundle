<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

/**
 * Event names dispatched by VaultBundle for listing and access checks.
 */
final class VaultEvents
{
    public const ITEM_LIST_QUERY = 'nowo_vault.item_list_query';

    public const ITEM_LIST_RESULT = 'nowo_vault.item_list_result';

    public const ITEM_ACCESS_CHECK = 'nowo_vault.item_access_check';

    /**
     * Fired to resolve view-only access for a user on a specific item.
     * Listeners call VaultItemReadOnlyEvent::markReadOnly() to enforce read-only mode.
     */
    public const ITEM_READ_ONLY_RESOLVE = 'nowo_vault.item_read_only_resolve';

    public const FOLDER_ACCESS_CHECK = 'nowo_vault.folder_access_check';

    /**
     * Fired when building the share UI for an item or folder.
     * Listeners add allowed users and teams (groups) via VaultGrantListQueryEvent::addGrantee().
     */
    public const GRANT_LIST_QUERY = 'nowo_vault.grant_list_query';
}

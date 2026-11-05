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

    /**
     * Fired before the default browser-extension login authenticator runs.
     * Listeners may call VaultBrowserExtensionAuthEvent::setResult() to handle auth.
     */
    public const BROWSER_EXTENSION_AUTH = 'nowo_vault.browser_extension_auth';

    /**
     * Fired after decrypted secret values of an item were returned to a user: a field revealed in the manage UI or a
     * login returned by the browser extension /logins lookup. {@see VaultItemRevealedEvent} carries ids, field names
     * and the channel only, never a value.
     */
    public const ITEM_REVEALED = 'nowo_vault.item_revealed';

    /**
     * Vetoable last check of VaultGrantService::grantAs() (1.16.0): {@see VaultGrantPolicyCheckEvent::deny()}.
     */
    public const GRANT_POLICY_CHECK = 'nowo_vault.grant_policy_check';

    /**
     * A grant was created (1.16.0, {@see VaultGrantChangeEvent}; after the flush).
     */
    public const GRANT_CREATED = 'nowo_vault.grant_created';

    /**
     * A grant got a new permission or expiry (1.16.0, {@see VaultGrantChangeEvent} with the previous values).
     */
    public const GRANT_UPDATED = 'nowo_vault.grant_updated';

    /**
     * A grant was revoked (1.16.0, {@see VaultGrantChangeEvent}, reason manual | erasure | resource_purged).
     */
    public const GRANT_REVOKED = 'nowo_vault.grant_revoked';

    /**
     * An expired grant was purged (1.16.0, {@see VaultGrantChangeEvent}, actor null). Access had already ended.
     */
    public const GRANT_EXPIRED = 'nowo_vault.grant_expired';

    /**
     * An item was created, updated, trashed, restored, purged or moved (1.16.0, {@see VaultItemChangeEvent}, ids only).
     */
    public const ITEM_CHANGED = 'nowo_vault.item_changed';
}

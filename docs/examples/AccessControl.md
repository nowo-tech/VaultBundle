# Vault access control events

VaultBundle mirrors the YopassBundle event pattern. Default access is **creator-only**; grants on items/folders extend access to users and teams.

## Events

| Event | Constant | Purpose |
|-------|----------|---------|
| Item list query | `VaultEvents::ITEM_LIST_QUERY` | Override shared/trash list queries |
| Item list result | `VaultEvents::ITEM_LIST_RESULT` | Filter or reorder listed items |
| Item access | `VaultEvents::ITEM_ACCESS_CHECK` | Grant team-based or custom ACL; call `markReadOnly()` |
| **Item read-only resolve** | `VaultEvents::ITEM_READ_ONLY_RESOLVE` | Mark specific items as view-only for a user |
| Folder access | `VaultEvents::FOLDER_ACCESS_CHECK` | Grant folder-level access |
| **Grant list query** | `VaultEvents::GRANT_LIST_QUERY` | Limit users/teams (groups) offered when sharing items or folders |

## Grant list (share picker)

When sharing an **item** or **folder**, dispatch `GRANT_LIST_QUERY` lets your app control who appears in the share form.

- Register choices with `VaultGrantListQueryEvent::addGrantee()`.
- Use `GranteeType::User` for individual users and `GranteeType::Team` for teams **or groups of people** (same concept, same id space).
- When at least one choice is added, the UI shows a grouped dropdown instead of a free-text id field.
- On submit, only listed grantees are accepted (server-side whitelist).

```php
use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantListQueryEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: VaultEvents::GRANT_LIST_QUERY)]
final class VaultGrantListListener
{
    public function __invoke(VaultGrantListQueryEvent $event): void
    {
        if ($event->getResourceType() === VaultResourceType::Folder && $event->getFolder() !== null) {
            // Example: only colleagues in the same org unit as the folder owner
        }

        $event->addGrantee(new VaultGranteeChoice(GranteeType::User, 'user-42', 'Alice'));
        $event->addGrantee(new VaultGranteeChoice(GranteeType::Team, 'group-sales', 'Sales group'));
    }
}
```

If no listener adds choices, the share form falls back to manual user/team id entry (backward compatible).

See also `examples/access-control/VaultGrantListListener.php`.

## Read-only mode

Use **`ITEM_READ_ONLY_RESOLVE`** when a user may **view** an item but must not edit, trash, share, restore, or purge it — even if they are the creator or hold a write/admin grant.

```php
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemReadOnlyEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: VaultEvents::ITEM_READ_ONLY_RESOLVE)]
final class VaultReadOnlyListener
{
    public function __invoke(VaultItemReadOnlyEvent $event): void
    {
        // Example: shared items from another team are view-only
        if ($this->teamPolicy->isViewOnlyShare($event->getUser(), $event->getItem())) {
            $event->markReadOnly();
        }
    }
}
```

Alternatively, on **`ITEM_ACCESS_CHECK`**, call `$event->markReadOnly()` to enforce view-only for that check (useful for action-specific rules).

### Behaviour

| Action | Allowed when read-only? |
|--------|-------------------------|
| `View` | Yes (if base ACL allows) |
| `Edit`, `Restore` | No |
| `Delete`, `Share`, `Purge` | No |

`VaultAccessGuard::isItemReadOnly()` and `resolveReadOnlyMap()` expose the flag for custom UI.

## Grants API

```php
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;

$grantService->grant(
    VaultResourceType::Item,
    $item->getId(),
    GranteeType::Team,
    'team-sales',
    VaultPermission::Read,
    $creator,
);
```

A `VaultPermission::Read` grant already limits writes at the ACL layer. Use **read-only events** when you need view access with **dynamic rules** (team policy, audit mode, temporary lock) without persisting a grant.

## Shared items view

The **Shared items** screen uses `VaultItemLister` with `sharedOnly: true`. Wire your team membership in an `ITEM_LIST_QUERY` listener and call `$event->overrideResult($items, $total)`.

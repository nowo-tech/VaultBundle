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
- **Without a listener nobody can be granted** (since 1.15.2, fail closed): the share page shows that sharing is not configured. `security.allow_unlisted_grantees: true` restores the pre-1.15.2 free-text behaviour, which accepts any typed id, including users of other tenants: unsafe, do not use it in multi-tenant hosts.

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

## Scope vaults

Since 1.16.0 a host scope can own vault items (`scopes.enabled: true`). The bundle asks your resolver, on every
request, who belongs to a scope and with which baseline permission:

```php
namespace App\Vault;

use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class ProjectScopeAccessResolver implements VaultScopeAccessResolverInterface
{
    public function __construct(private ProjectMemberRepository $members)
    {
    }

    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        if (!$user instanceof User || !str_starts_with($scope, 'project-')) {
            return null;                                   // never emit user-* keys: personal vaults are not scopes
        }

        $role = $this->members->roleOf($user, substr($scope, 8));

        return match ($role) {
            null      => null,                             // not a member: nothing, grants included
            'viewer'  => new VaultScopeAccess(VaultPermission::Read, catalogue: true),
            'editor'  => new VaultScopeAccess(VaultPermission::Write, catalogue: true, create: true),
            'manager' => new VaultScopeAccess(VaultPermission::Admin, catalogue: true, health: true, create: true),
            default   => new VaultScopeAccess(null),       // member: explicit grants only
        };
    }
}
```

```yaml
nowo_vault:
    scopes: { enabled: true, access_resolver: App\Vault\ProjectScopeAccessResolver }
    security: { deny_status: 404 }
```

Keep the resolver cheap (it runs once per user and scope per request) and never cache its answer across requests.
Namespace team ids per tenant if you grant teams (`org-<uuid>:group-<uuid>`).

### Approving an access request

```php
$result = $grantService->grantAs($approver, VaultResourceType::Item, $itemId, GranteeType::User, $requesterId, VaultPermission::Read, $clock->now()->modify('+7 days'));
```

`grantAs()` re-checks that `$approver` may Share the item now and that the requester belongs to the scope, and caps
temporary grants (`grants.temporary`). Veto special cases with a `GRANT_POLICY_CHECK` listener:

```php
#[AsEventListener(event: VaultEvents::GRANT_POLICY_CHECK)]
public function onPolicy(VaultGrantPolicyCheckEvent $event): void
{
    if ($event->getGranteeType() === GranteeType::Team && $this->isOwnTeam($event->getActor(), $event->getGranteeId())) {
        $event->deny('own_team');
    }
}
```

## Re-authentication for the decrypted export

`export.enabled` needs a `VaultReauthenticationCheckerInterface` service (`export.reauth_checker`). Build the proof from
your re-authentication step only (password, then TOTP / WebAuthn when the user has MFA), bind it to the session id
(after regenerating it), and never return it again once `consume()` ran. A full example is in
[USAGE](../USAGE.md#decrypted-export); the obligations are in [SECURITY](../SECURITY.md#personal-data-and-decrypted-export-1160).

```yaml
nowo_vault:
    export:
        enabled: true
        reauth_checker: App\Vault\Security\ReauthChecker
        accepted_mfa_factors: [totp, webauthn]   # recovery codes do not unlock a plaintext dump
```

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

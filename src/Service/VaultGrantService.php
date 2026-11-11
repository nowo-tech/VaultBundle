<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Event\VaultGrantPolicyCheckEvent;
use Nowo\VaultBundle\Exception\VaultGrantRefusedException;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantExpiryRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use const DATE_ATOM;

/**
 * Creates, updates, lists and revokes sharing grants.
 *
 * Since 1.16.0 {@see self::grantAs()} and {@see self::revokeAs()} are the entry points for the UI and for host flows
 * (access-request approvals): they re-check at the call that the actor may Share the resource (REQ-SEC-001), apply
 * the temporary-grant rules and emit the GRANT_* events. Writes are race-safe (conditional UPDATE / DELETE with an
 * affected-rows check) with the Doctrine repository.
 */
final readonly class VaultGrantService
{
    /**
     * Stores the grant repository, the optional logger and the 1.16.0 collaborators (all optional for BC: grantAs()
     * and revokeAs() need the access guard and the item / folder repositories).
     *
     * @param array{min_ttl?: string, max_ttl?: string, max_permission?: string} $temporary grants.temporary config
     */
    public function __construct(
        private VaultGrantRepositoryInterface $grantRepository,
        private ?LoggerInterface $logger = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ClockInterface $clock = null,
        private ?VaultAccessGuard $accessGuard = null,
        private ?VaultItemRepositoryInterface $itemRepository = null,
        private ?VaultFolderRepositoryInterface $folderRepository = null,
        private ?VaultGrantListResolver $grantListResolver = null,
        private ?VaultUserLocatorInterface $userLocator = null,
        private array $temporary = [],
    ) {
    }

    /**
     * Grants (or updates) access to an item or folder on behalf of an actor (1.16.0).
     *
     * Checks, in order: the resource exists and is active and the actor may Share it now; no self grant; the
     * grantee is allowed by GRANT_LIST_QUERY (fail closed); a user grantee of a scope resource is a member of the
     * scope; a temporary grant respects `grants.temporary` (TTL range, permission cap); GRANT_POLICY_CHECK. A
     * temporary grant never replaces or upgrades a permanent one (Q-O6).
     *
     * @throws VaultGrantRefusedException with a reason code when a check fails
     */
    public function grantAs(
        UserInterface $actor,
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
        VaultPermission $permission,
        ?DateTimeImmutable $expiresAt = null,
    ): VaultGrantResult {
        $resource = $this->loadShareableResource($actor, $resourceType, $resourceId);
        $actorId  = UserIdResolver::getId($actor);

        if ($granteeType === GranteeType::User && $actorId !== null && $granteeId === $actorId) {
            $this->refuse(VaultGrantRefusedException::SELF_GRANT, $actorId, $resourceType, $resourceId);
        }

        if ($this->grantListResolver instanceof VaultGrantListResolver) {
            $list = $resource instanceof VaultItem
                ? $this->grantListResolver->resolveForItem($actor, $resource)
                : $this->grantListResolver->resolveForFolder($actor, $resource);
            if (!$list->isGranteeAllowed($granteeType, $granteeId)) {
                $this->refuse(VaultGrantRefusedException::GRANTEE_NOT_ALLOWED, $actorId, $resourceType, $resourceId);
            }
        }

        $scope = $resource->getOwnerScope();
        if ($scope !== null && $granteeType === GranteeType::User) {
            $grantee = $this->userLocator?->find($granteeId);
            if (!$grantee instanceof UserInterface || !$this->guard()->resolveScopeAccess($grantee, $scope) instanceof VaultScopeAccess) {
                $this->refuse(VaultGrantRefusedException::GRANTEE_NOT_IN_SCOPE, $actorId, $resourceType, $resourceId);
            }
        }

        if ($expiresAt instanceof DateTimeImmutable) {
            $now = $this->now();
            // @igor-ignore - DateTimeImmutable::add() returns a new instance; the clock is never mutated.
            if ($expiresAt < $now->add($this->interval('min_ttl', 'PT5M')) || $expiresAt > $now->add($this->interval('max_ttl', 'P30D'))) {
                $this->refuse(VaultGrantRefusedException::EXPIRY_OUT_OF_RANGE, $actorId, $resourceType, $resourceId);
            }
            if ($this->rank($permission) > $this->rank($this->maxTemporaryPermission())) {
                $this->refuse(VaultGrantRefusedException::TEMPORARY_PERMISSION_TOO_HIGH, $actorId, $resourceType, $resourceId);
            }
        }

        $policy = new VaultGrantPolicyCheckEvent($actor, $resourceType, $resourceId, $granteeType, $granteeId, $permission, $expiresAt);
        $this->eventDispatcher?->dispatch($policy, VaultEvents::GRANT_POLICY_CHECK);
        if ($policy->isDenied()) {
            $this->refuse((string) $policy->getDeniedCode(), $actorId, $resourceType, $resourceId);
        }

        return $this->upsert($resourceType, $resourceId, $granteeType, $granteeId, $permission, $expiresAt, $actor, $actorId)[0];
    }

    /**
     * Creates a grant, or updates the permission of the existing grant for the same grantee.
     *
     * @deprecated since 1.16.0, use grantAs() (this method does not re-check the actor and creates permanent grants
     *             only). It now emits GRANT_CREATED / GRANT_UPDATED (actor null).
     */
    public function grant(
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
        VaultPermission $permission,
        object $createdBy,
    ): VaultGrant {
        return $this->upsert($resourceType, $resourceId, $granteeType, $granteeId, $permission, null, $createdBy, null)[1];
    }

    /**
     * Revokes a grant on behalf of an actor (1.16.0): re-checks that the actor may Share the resource, then deletes
     * the row conditionally. Emits GRANT_REVOKED (reason `manual`) only when this call deleted it.
     *
     * @throws VaultGrantRefusedException when the actor may not Share the resource
     *
     * @return bool false when the grant was already gone (revoked or purged meanwhile)
     */
    public function revokeAs(UserInterface $actor, VaultGrant $grant): bool
    {
        $this->loadShareableResource($actor, $grant->getResourceType(), $grant->getResourceId(), allowTrashed: true);

        return $this->delete($grant, UserIdResolver::getId($actor), VaultGrantChangeEvent::REASON_MANUAL);
    }

    /**
     * Deletes a grant (system revoke, no actor check).
     *
     * @deprecated since 1.16.0, use revokeAs(). It now emits GRANT_REVOKED (reason `manual`, actor null).
     */
    public function revoke(VaultGrant $grant): void
    {
        $this->delete($grant, null, VaultGrantChangeEvent::REASON_MANUAL);
    }

    /**
     * Returns the grants of one resource (expired, not yet purged grants included: the share page flags them).
     *
     * @return list<VaultGrant>
     */
    public function listForResource(VaultResourceType $type, string $resourceId): array
    {
        return $this->grantRepository->findByResource($type, $resourceId);
    }

    /**
     * Returns the active grant count per resource id.
     *
     * @param list<string> $resourceIds
     *
     * @return array<string, int>
     */
    public function countForResources(VaultResourceType $type, array $resourceIds): array
    {
        return $this->grantRepository->countByResources($type, $resourceIds);
    }

    /**
     * Returns the grant with the given id, or null.
     */
    public function findById(string $id): ?VaultGrant
    {
        return $this->grantRepository->findById($id);
    }

    /**
     * Dispatches a grant change event (after the flush), stamped with the injected clock (1.16.0).
     */
    public function dispatchChange(
        string $eventName,
        VaultGrant $grant,
        ?string $actorId,
        ?string $reason = null,
        ?VaultPermission $previousPermission = null,
        ?DateTimeImmutable $previousExpiresAt = null,
        ?string $ownerKey = null,
    ): void {
        $this->eventDispatcher?->dispatch(new VaultGrantChangeEvent(
            $grant->getId(),
            $grant->getResourceType(),
            $grant->getResourceId(),
            $ownerKey ?? $this->ownerKey($grant),
            $grant->getGranteeType(),
            $grant->getGranteeId(),
            $grant->getPermission(),
            $grant->getExpiresAt(),
            $actorId,
            $this->now(),
            $previousPermission,
            $previousExpiresAt,
            $reason,
        ), $eventName);
    }

    /**
     * Upsert of one row per resource + grantee (contract §4.3), race-safe with the 1.16.0 repository.
     *
     * @return array{0: VaultGrantResult, 1: VaultGrant}
     */
    private function upsert(
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
        VaultPermission $permission,
        ?DateTimeImmutable $expiresAt,
        object $createdBy,
        ?string $actorId,
    ): array {
        $existing = $this->grantRepository->findOne($resourceType, $resourceId, $granteeType, $granteeId);
        if ($existing instanceof VaultGrant) {
            return $this->update($existing, $permission, $expiresAt, $createdBy, $actorId);
        }

        $grant = new VaultGrant($resourceType, $resourceId, $granteeType, $granteeId, $permission, $createdBy, $expiresAt);

        try {
            $this->insert($grant);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request inserted the same grantee first: retry once as the update path.
            $existing = $this->grantRepository->findOne($resourceType, $resourceId, $granteeType, $granteeId);
            if (!$existing instanceof VaultGrant) {
                // No exact row behind the violation: on a schema whose grantee_id still folds case (no binary collation,
                // id mapping), another grantee's row holds the unique key. Refuse instead of a 500 (LQ-V-P3-I1).
                $this->logger()->warning('Vault grant refused: unique key held by another grantee (check the grantee_id collation).', [
                    'bundle'    => 'nowo_vault',
                    'exception' => $exception::class,
                ]);
                $this->refuse(VaultGrantRefusedException::GRANTEE_NOT_ALLOWED, $actorId, $resourceType, $resourceId);
            }

            return $this->update($existing, $permission, $expiresAt, $createdBy, $actorId);
        }

        $this->created($grant, $actorId);

        return [VaultGrantResult::Created, $grant];
    }

    /**
     * Update path of the upsert (contract §4.3 rows 2 to 6).
     *
     * @return array{0: VaultGrantResult, 1: VaultGrant}
     */
    private function update(VaultGrant $existing, VaultPermission $permission, ?DateTimeImmutable $expiresAt, object $createdBy, ?string $actorId): array
    {
        $previousPermission = $existing->getPermission();
        $previousExpiresAt  = $existing->getExpiresAt();

        if (!$existing->isTemporary() && $expiresAt instanceof DateTimeImmutable) {
            // A temporary grant never replaces a permanent one: covered => unchanged, an upgrade => refused (Q-O6).
            if ($this->rank($permission) <= $this->rank($previousPermission)) {
                return [VaultGrantResult::Unchanged, $existing];
            }

            $this->refuse(VaultGrantRefusedException::WOULD_REPLACE_PERMANENT, $actorId, $existing->getResourceType(), $existing->getResourceId());
        }

        if ($this->grantRepository instanceof VaultGrantExpiryRepositoryInterface) {
            if (!$this->grantRepository->updatePermissionAndExpiry($existing->getId(), $permission, $expiresAt)) {
                // Purged meanwhile: retry once as an insert.
                $grant = new VaultGrant($existing->getResourceType(), $existing->getResourceId(), $existing->getGranteeType(), $existing->getGranteeId(), $permission, $createdBy, $expiresAt);
                $this->insert($grant);
                $this->created($grant, $actorId);

                return [VaultGrantResult::Created, $grant];
            }
            $existing->setPermission($permission)->setExpiresAt($expiresAt);
        } else {
            $existing->setPermission($permission)->setExpiresAt($expiresAt);
            $this->grantRepository->save($existing);
        }

        $this->logGrant('Vault grant updated.', 'grant_update', $existing, $actorId);
        $this->dispatchChange(VaultEvents::GRANT_UPDATED, $existing, $actorId, null, $previousPermission, $previousExpiresAt);

        return [VaultGrantResult::Updated, $existing];
    }

    private function created(VaultGrant $grant, ?string $actorId): void
    {
        $this->logGrant('Vault grant created.', 'grant_create', $grant, $actorId);
        $this->dispatchChange(VaultEvents::GRANT_CREATED, $grant, $actorId);
    }

    /**
     * Deletes a grant (conditional with the 1.16.0 repository) and emits GRANT_REVOKED when this call deleted it.
     */
    private function delete(VaultGrant $grant, ?string $actorId, string $reason): bool
    {
        $ownerKey = $this->ownerKey($grant);
        if ($this->grantRepository instanceof VaultGrantExpiryRepositoryInterface) {
            if (!$this->grantRepository->deleteById($grant->getId())) {
                return false;
            }
        } else {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->grantRepository->remove($grant);
        }

        $this->logGrant('Vault grant revoked.', 'grant_revoke', $grant, $actorId);
        $this->dispatchChange(VaultEvents::GRANT_REVOKED, $grant, $actorId, $reason, ownerKey: $ownerKey);

        return true;
    }

    private function insert(VaultGrant $grant): void
    {
        if ($this->grantRepository instanceof VaultGrantExpiryRepositoryInterface) {
            $this->grantRepository->insert($grant);

            return;
        }

        $this->grantRepository->save($grant);
    }

    /**
     * Loads the resource and checks that the actor may Share it now. Every failure is `actor_cannot_share`: the code
     * does not tell a missing resource from a forbidden one.
     */
    private function loadShareableResource(UserInterface $actor, VaultResourceType $type, string $resourceId, bool $allowTrashed = false): VaultItem|VaultFolder
    {
        $resource = match ($type) {
            VaultResourceType::Item   => $this->itemRepository()->findById($resourceId),
            VaultResourceType::Folder => $this->folderRepository()->findById($resourceId),
        };

        if ($resource instanceof VaultItem && ($allowTrashed || !$resource->isDeleted()) && $this->guard()->canAccessItem($actor, $resource, VaultAccessAction::Share)) {
            return $resource;
        }

        if ($resource instanceof VaultFolder && ($allowTrashed || !$resource->isDeleted()) && $this->guard()->canAccessFolder($actor, $resource, VaultAccessAction::Share)) {
            return $resource;
        }

        $this->refuse(VaultGrantRefusedException::ACTOR_CANNOT_SHARE, UserIdResolver::getId($actor), $type, $resourceId);
    }

    /**
     * Logs the refusal (notice, ids only) and throws.
     */
    private function refuse(string $code, ?string $actorId, VaultResourceType $type, string $resourceId): never
    {
        $this->logger()->notice('Vault grant refused.', [
            'bundle'        => 'nowo_vault',
            'action'        => 'grant_refused',
            'code'          => $code,
            'actor_id'      => $actorId,
            'resource_type' => $type->value,
            'resource_id'   => $resourceId,
        ]);

        throw new VaultGrantRefusedException($code);
    }

    /**
     * Owner key of the grant's resource (null when it no longer exists or no repository can tell).
     */
    private function ownerKey(VaultGrant $grant): ?string
    {
        if ($this->grantRepository instanceof VaultGrantExpiryRepositoryInterface) {
            $keys = $this->grantRepository->resolveOwnerKeys([[$grant->getResourceType(), $grant->getResourceId()]]);

            return $keys[$grant->getResourceType()->value . ':' . $grant->getResourceId()] ?? null;
        }

        $resource = match ($grant->getResourceType()) {
            VaultResourceType::Item   => $this->itemRepository?->findById($grant->getResourceId()),
            VaultResourceType::Folder => $this->folderRepository?->findById($grant->getResourceId()),
        };

        return $resource?->getOwner()->key();
    }

    private function interval(string $key, string $default): DateInterval
    {
        return new DateInterval($this->temporary[$key] ?? $default);
    }

    private function maxTemporaryPermission(): VaultPermission
    {
        return VaultPermission::tryFrom($this->temporary['max_permission'] ?? 'read') ?? VaultPermission::Read;
    }

    private function rank(VaultPermission $permission): int
    {
        return match ($permission) {
            VaultPermission::Read  => 1,
            VaultPermission::Write => 2,
            VaultPermission::Admin => 3,
        };
    }

    private function guard(): VaultAccessGuard
    {
        return $this->accessGuard ?? throw new LogicException('VaultGrantService::grantAs() / revokeAs() need the VaultAccessGuard.');
    }

    private function itemRepository(): VaultItemRepositoryInterface
    {
        return $this->itemRepository ?? throw new LogicException('VaultGrantService::grantAs() / revokeAs() need the item repository.');
    }

    private function folderRepository(): VaultFolderRepositoryInterface
    {
        return $this->folderRepository ?? throw new LogicException('VaultGrantService::grantAs() / revokeAs() need the folder repository.');
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }

    /**
     * Logs a sharing change with identifiers only (REQ-OBS-001): no item titles or payload data. Since 1.16.0 the
     * context also carries `expires_at` and `actor_id` when they have a value.
     */
    private function logGrant(string $message, string $action, VaultGrant $grant, ?string $actorId = null): void
    {
        $context = [
            'bundle'        => 'nowo_vault',
            'action'        => $action,
            'resource_type' => $grant->getResourceType()->value,
            'resource_id'   => $grant->getResourceId(),
            'grantee_type'  => $grant->getGranteeType()->value,
            'grantee_id'    => $grant->getGranteeId(),
            'permission'    => $grant->getPermission()->value,
        ];
        if ($grant->getExpiresAt() instanceof DateTimeImmutable) {
            $context['expires_at'] = $grant->getExpiresAt()->format(DATE_ATOM);
        }
        if ($actorId !== null) {
            $context['actor_id'] = $actorId;
        }

        $this->logger()->info($message, $context);
    }
}

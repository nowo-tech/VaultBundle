<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use DateTimeImmutable;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Service\VaultGrantService;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * GRANT_POLICY_CHECK (1.16.0): the last check of {@see VaultGrantService::grantAs()}. A
 * listener may veto the grant with a reason code (for example, no self-team grants after a demotion).
 */
final class VaultGrantPolicyCheckEvent extends Event
{
    private ?string $deniedCode = null;

    /**
     * Creates the event for one grant request.
     */
    public function __construct(
        private readonly UserInterface $actor,
        private readonly VaultResourceType $resourceType,
        private readonly string $resourceId,
        private readonly GranteeType $granteeType,
        private readonly string $granteeId,
        private readonly VaultPermission $permission,
        private readonly ?DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * The acting user.
     */
    public function getActor(): UserInterface
    {
        return $this->actor;
    }

    /**
     * Resource type.
     */
    public function getResourceType(): VaultResourceType
    {
        return $this->resourceType;
    }

    /**
     * Resource id.
     */
    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    /**
     * Grantee type.
     */
    public function getGranteeType(): GranteeType
    {
        return $this->granteeType;
    }

    /**
     * Grantee id.
     */
    public function getGranteeId(): string
    {
        return $this->granteeId;
    }

    /**
     * Requested permission.
     */
    public function getPermission(): VaultPermission
    {
        return $this->permission;
    }

    /**
     * Requested expiry (null = permanent).
     */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Refuses the grant with a reason code (default `policy_denied`).
     */
    public function deny(string $code = 'policy_denied'): void
    {
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->deniedCode = $code !== '' ? $code : 'policy_denied';
    }

    /**
     * Whether a listener refused the grant.
     */
    public function isDenied(): bool
    {
        return $this->deniedCode !== null;
    }

    /**
     * Reason code of the refusal, or null.
     */
    public function getDeniedCode(): ?string
    {
        return $this->deniedCode;
    }
}

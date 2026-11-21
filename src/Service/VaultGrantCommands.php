<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\VaultBundle\Doctrine\VaultWriteUnit;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultGrantRefusedException;
use Nowo\VaultBundle\Internal\VaultCommandsTrait;
use Nowo\VaultBundle\Internal\VaultRowLock;
use Nowo\VaultBundle\Internal\VaultViews;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultGrantView;

use function array_map;

/**
 * The vault's {@see VaultGrantCommandsInterface} (2.1.0) over {@see VaultGrantService} and {@see VaultAccessGuard}.
 * Stateless (FrankenPHP worker mode, kernel reset off).
 */
final readonly class VaultGrantCommands implements VaultGrantCommandsInterface
{
    use VaultCommandsTrait;

    /**
     * @param EntityManagerInterface $entityManager the vault's private manager (exact-id lookups)
     */
    public function __construct(
        private VaultGrantService $grants,
        private VaultAccessGuard $guard,
        private VaultUserRefResolver $refs,
        private VaultUserReferenceResolverInterface $references,
        private EntityManagerInterface $entityManager,
        private ?VaultWriteUnit $writeUnit = null,
    ) {
    }

    public function grant(
        VaultUserRef $actor,
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
        VaultPermission $permission,
        ?DateTimeImmutable $expiresAt = null,
    ): VaultGrantView {
        $user = $this->actor($actor);

        return $this->inUnit(function () use ($user, $resourceType, $resourceId, $granteeType, $granteeId, $permission, $expiresAt): VaultGrantView {
            // Exact resource id (audit YF-L2): a case or padding variant the database would match is refused like a
            // resource the actor may not share, so no grant row is ever stored with a variant id.
            if ($this->resource($resourceType, $resourceId) === null) {
                throw new VaultGrantRefusedException(VaultGrantRefusedException::ACTOR_CANNOT_SHARE);
            }
            // grantAs() re-checks Share and every sharing rule; it refuses with VaultGrantRefusedException.
            $result = $this->grants->grantAs($user, $resourceType, $resourceId, $granteeType, $granteeId, $permission, $expiresAt);
            foreach ($this->grants->listForResource($resourceType, $resourceId) as $grant) {
                if ($grant->getGranteeType() === $granteeType && $grant->getGranteeId() === $granteeId) {
                    return VaultViews::grant($grant, $result);
                }
            }

            // @codeCoverageIgnoreStart
            throw new LogicException('The grant written in this unit was not found.');
            // @codeCoverageIgnoreEnd
        });
    }

    public function revoke(VaultUserRef $actor, string $grantId): bool
    {
        $user = $this->actor($actor);

        return $this->inUnit(function () use ($user, $grantId): bool {
            $grant = $grantId !== '' ? $this->grants->findById($grantId) : null;
            if (!$grant instanceof VaultGrant || $grant->getId() !== $grantId) {
                // The same answer as a grant of a resource the actor may not share (audit YF-I1).
                throw new VaultGrantRefusedException(VaultGrantRefusedException::ACTOR_CANNOT_SHARE);
            }

            // revokeAs() re-checks Share on the resource; it refuses with VaultGrantRefusedException.
            return $this->grants->revokeAs($user, $grant);
        });
    }

    public function listForResource(VaultUserRef $actor, VaultResourceType $resourceType, string $resourceId): array
    {
        $user     = $this->actor($actor);
        $resource = $this->resource($resourceType, $resourceId);
        $allowed  = match (true) {
            $resource instanceof VaultItem   => !$resource->isDeleted() && $this->guard->canAccessItem($user, $resource, VaultAccessAction::Share),
            $resource instanceof VaultFolder => !$resource->isDeleted() && $this->guard->canAccessFolder($user, $resource, VaultAccessAction::Share),
            default                          => false,
        };
        if (!$allowed) {
            throw new VaultAccessDeniedException();
        }

        return array_map(static fn (VaultGrant $grant): VaultGrantView => VaultViews::grant($grant), $this->grants->listForResource($resourceType, $resourceId));
    }

    /**
     * The item or folder with exactly this id (byte for byte), or null.
     */
    private function resource(VaultResourceType $type, string $resourceId): VaultItem|VaultFolder|null
    {
        return VaultRowLock::find($this->entityManager, $type === VaultResourceType::Item ? VaultItem::class : VaultFolder::class, $resourceId);
    }
}

<?php

declare(strict_types=1);

// Literal copy of v1.18.0 src/Support/DoctrineVaultUserReferenceResolver.php (equivalence reference of 1.19.0): only the namespace, the class name and the
// imports differ. Never edit the logic.

namespace Nowo\VaultBundle\Tests\Support\Legacy;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function get_debug_type;
use function is_object;
use function ltrim;
use function method_exists;
use function sprintf;

/**
 * Default {@see VaultUserReferenceResolverInterface} (1.17.0).
 *
 * Relation mapping: `user_class` instances unchanged; an instance of a class listed in `accepted_user_classes` with a
 * usable `getId()` becomes `getReference(user_class, id)` on the vault's entity manager (no query). Id mapping: the
 * string id of any accepted user. Any other user is refused with an AccessDeniedException: matching it by its id would
 * let Admin#5 open the vault of Customer#5. Stateless: safe in a kept-alive worker.
 */
final readonly class DoctrineVaultUserReferenceResolverV1180 implements VaultUserReferenceResolverInterface
{
    /**
     * @param string $userClass `nowo_vault.user_class`
     * @param VaultUserClassPolicyV1180 $classes accepted security-user classes
     * @param string $userMapping `nowo_vault.user_mapping` (`relation` or `id`)
     * @param ?ManagerRegistry $registry Doctrine registry (null = accepted users pass unchanged in relation mapping)
     * @param ?string $managerName the vault's entity manager
     */
    public function __construct(
        private string $userClass,
        private VaultUserClassPolicyV1180 $classes,
        private string $userMapping = VaultUserMapping::RELATION,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
    }

    public function mapsById(): bool
    {
        return $this->userMapping === VaultUserMapping::ID;
    }

    public function isAccepted(object $user): bool
    {
        return $this->classes->idOf($user) !== null;
    }

    public function reference(object $user): object|string
    {
        $id = $this->classes->idOf($user);
        if ($id === null) {
            throw new AccessDeniedException(sprintf('nowo_vault: a %s is not a vault user (not nowo_vault.user_class nor listed in nowo_vault.accepted_user_classes with a usable getId()).', get_debug_type($user)));
        }

        if ($this->mapsById()) {
            return (string) $id;
        }

        if ($this->classes->isUserClass($user) || !$this->registry instanceof ManagerRegistry) {
            return $user;
        }

        $manager = $this->registry->getManager($this->managerName);
        if (!method_exists($manager, 'getReference')) {
            return $user;
        }

        $reference = $manager->getReference(ltrim($this->userClass, '\\'), $id);

        return is_object($reference) ? $reference : $user;
    }
}

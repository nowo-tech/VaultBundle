<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Internal;

use InvalidArgumentException;
use Nowo\VaultBundle\Doctrine\VaultWriteUnit;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function mb_strlen;
use function sprintf;
use function trim;

/**
 * Shared steps of the 2.1 command services: the actor a {@see VaultUserRef} stands for, one write unit per call and the
 * text checks.
 *
 * Requires the using class to declare `VaultUserRefResolver $refs`, `VaultUserReferenceResolverInterface $references`
 * and `?VaultWriteUnit $writeUnit`.
 *
 * @internal
 */
trait VaultCommandsTrait
{
    /**
     * The user the vault trusts for the reference (audit NJ-1: a reference by id is looked up again).
     *
     * @throws VaultAccessDeniedException when the reference resolves to no user the vault accepts
     */
    private function actor(VaultUserRef $ref): UserInterface
    {
        $user = $this->refs->resolve($ref);
        if (!$user instanceof UserInterface || !$this->references->isAccepted($user)) {
            throw new VaultAccessDeniedException();
        }

        return $user;
    }

    /**
     * Runs the operation in one vault write unit (directly when the service was built without one).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function inUnit(callable $operation): mixed
    {
        return $this->writeUnit instanceof VaultWriteUnit ? $this->writeUnit->run($operation) : $operation();
    }

    /**
     * @throws InvalidArgumentException when the text is blank or longer than the column
     */
    private static function requireText(string $value, int $max, string $what): string
    {
        if (trim($value) === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException(sprintf('The %s is 1 to %d characters and not blank.', $what, $max));
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Doctrine\VaultExtensionTokenTable;
use Nowo\VaultBundle\Doctrine\VaultSavepoint;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_filter;
use function array_values;

/**
 * Doctrine ORM repository of browser extension tokens.
 */
final readonly class DoctrineOrmVaultExtensionTokenRepository implements VaultExtensionTokenRepositoryInterface
{
    use BindsVaultUserTrait;
    use ResolvesEntityManagerTrait;

    /**
     * Stores the entity manager, the optional registry / manager name and the optional clock.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private ?ClockInterface $clock = null,
        private ?VaultUserReferenceResolverInterface $users = null,
        /** 1.18.0 (NC-1): purge through raw SQL while the entity is not mapped. */
        private ?VaultExtensionTokenTable $tokenTable = null,
    ) {
    }

    /**
     * Persists and flushes a token.
     */
    public function save(VaultExtensionToken $token): void
    {
        $this->em()->persist($token);
        $this->em()->flush();
    }

    /**
     * Removes and flushes a token.
     */
    public function remove(VaultExtensionToken $token): void
    {
        $this->em()->remove($token);
        $this->em()->flush();
    }

    /**
     * Returns the unexpired token with the given hash, or null.
     */
    public function findValidByTokenHash(string $tokenHash): ?VaultExtensionToken
    {
        /* @var VaultExtensionToken|null */
        return $this->em()->createQueryBuilder()
            ->select('t')
            ->from(VaultExtensionToken::class, 't')
            ->where('t.tokenHash = :hash')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', $this->now())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns every token of the user.
     */
    public function findByUser(object $user): array
    {
        $userId = UserIdResolver::getId($user);
        if ($userId === null) {
            return [];
        }

        /** @var list<VaultExtensionToken> $tokens */
        $tokens = $this->em()->createQueryBuilder()
            ->select('t')
            ->from(VaultExtensionToken::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $this->userParameter($user))
            ->getQuery()
            ->getResult();

        // 1.17.0 (LQ-V-M1): only the user's own tokens, compared byte for byte, whatever the column collation.
        return array_values(array_filter($tokens, static fn (VaultExtensionToken $token): bool => $token->getUserId() === $userId));
    }

    /**
     * Deletes expired tokens and returns how many were removed.
     */
    public function removeExpired(): int
    {
        // 1.18.0 (NC-1): the entity is not mapped (database.conditional_mapping, extension off), but rows of an earlier
        // release may remain: purge them through the table itself.
        if ($this->em()->getMetadataFactory()->isTransient(VaultExtensionToken::class)) {
            return ($this->tokenTable ?? new VaultExtensionTokenTable())->deleteExpired($this->em(), $this->now());
        }

        $query = $this->em()->createQueryBuilder()
            ->delete(VaultExtensionToken::class, 't')
            ->where('t.expiresAt <= :now')
            ->setParameter('now', $this->now())
            ->getQuery();

        return (int) VaultSavepoint::write($this->em()->getConnection(), static fn (): mixed => $query->execute(), $this->em());
    }

    /**
     * Returns the current time from the injected clock (REQ-DI-001), or the system clock when none is wired.
     */
    private function now(): DateTimeImmutable
    {
        return ($this->clock ?? new NativeClock())->now();
    }
}

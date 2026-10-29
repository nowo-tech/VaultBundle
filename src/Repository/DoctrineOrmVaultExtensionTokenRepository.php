<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Doctrine ORM repository of browser extension tokens.
 */
final readonly class DoctrineOrmVaultExtensionTokenRepository implements VaultExtensionTokenRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    /**
     * Stores the entity manager, the optional registry / manager name and the optional clock.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private ?ClockInterface $clock = null,
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

        /* @var list<VaultExtensionToken> */
        return $this->em()->createQueryBuilder()
            ->select('t')
            ->from(VaultExtensionToken::class, 't')
            ->innerJoin('t.user', 'u')
            ->where('u = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes expired tokens and returns how many were removed.
     */
    public function removeExpired(): int
    {
        return $this->em()->createQueryBuilder()
            ->delete(VaultExtensionToken::class, 't')
            ->where('t.expiresAt <= :now')
            ->setParameter('now', $this->now())
            ->getQuery()
            ->execute();
    }

    /**
     * Returns the current time from the injected clock (REQ-DI-001), or the system clock when none is wired.
     */
    private function now(): DateTimeImmutable
    {
        return ($this->clock ?? new NativeClock())->now();
    }
}

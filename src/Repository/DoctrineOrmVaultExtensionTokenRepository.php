<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Support\UserIdResolver;

final readonly class DoctrineOrmVaultExtensionTokenRepository implements VaultExtensionTokenRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(VaultExtensionToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function remove(VaultExtensionToken $token): void
    {
        $this->entityManager->remove($token);
        $this->entityManager->flush();
    }

    public function findValidByTokenHash(string $tokenHash): ?VaultExtensionToken
    {
        /* @var VaultExtensionToken|null */
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(VaultExtensionToken::class, 't')
            ->where('t.tokenHash = :hash')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUser(object $user): array
    {
        $userId = UserIdResolver::getId($user);
        if ($userId === null) {
            return [];
        }

        /* @var list<VaultExtensionToken> */
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(VaultExtensionToken::class, 't')
            ->innerJoin('t.user', 'u')
            ->where('u = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    public function removeExpired(): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(VaultExtensionToken::class, 't')
            ->where('t.expiresAt <= :now')
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}

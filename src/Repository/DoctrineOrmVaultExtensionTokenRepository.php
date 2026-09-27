<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Support\UserIdResolver;

final readonly class DoctrineOrmVaultExtensionTokenRepository implements VaultExtensionTokenRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
    }

    public function save(VaultExtensionToken $token): void
    {
        $this->em()->persist($token);
        $this->em()->flush();
    }

    public function remove(VaultExtensionToken $token): void
    {
        $this->em()->remove($token);
        $this->em()->flush();
    }

    public function findValidByTokenHash(string $tokenHash): ?VaultExtensionToken
    {
        /* @var VaultExtensionToken|null */
        return $this->em()->createQueryBuilder()
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
        return $this->em()->createQueryBuilder()
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
        return $this->em()->createQueryBuilder()
            ->delete(VaultExtensionToken::class, 't')
            ->where('t.expiresAt <= :now')
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}

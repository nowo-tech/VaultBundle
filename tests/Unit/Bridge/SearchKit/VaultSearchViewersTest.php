<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Bridge\SearchKit;

use Nowo\VaultBundle\Bridge\SearchKit\VaultSearchViewer;
use Nowo\VaultBundle\Bridge\SearchKit\VaultSearchViewers;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Support\DoctrineVaultUserReferenceResolver;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\Ddd\OtherDomainUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The viewer of a search: the signed-in vault user with exactly the query's identifier, fail closed otherwise.
 */
#[CoversClass(VaultSearchViewers::class)]
#[CoversClass(VaultSearchViewer::class)]
final class VaultSearchViewersTest extends TestCase
{
    public function testTheSignedInVaultUserWithTheSameIdentifier(): void
    {
        $viewers = new VaultSearchViewers($this->idMapping(), $this->tokens(new DddUser('alice', 'alice@example.com')));

        $viewer = $viewers->forIdentifier('alice@example.com');
        self::assertInstanceOf(VaultSearchViewer::class, $viewer);
        self::assertSame('alice', $viewer->id);
        self::assertSame('alice', $viewer->reference);
        self::assertSame($viewer->id, $viewers->forTenant('alice', 'alice@example.com')?->id);
        self::assertNull($viewers->forTenant('Alice', 'alice@example.com'), 'The tenant is the id, byte for byte.');
        self::assertNull($viewers->forTenant(5, 'alice@example.com'));
        self::assertNull($viewers->forIdentifier('Alice@example.com'), 'The identifier, byte for byte.');
    }

    public function testNoTokenNoUserOrAForeignUserGiveNoViewer(): void
    {
        self::assertNull((new VaultSearchViewers($this->idMapping()))->forIdentifier('a'));
        self::assertNull((new VaultSearchViewers($this->idMapping(), new TokenStorage()))->forIdentifier('a'));
        self::assertNull((new VaultSearchViewers($this->idMapping(), $this->tokens(new OtherDomainUser('alice', 'a'))))->forIdentifier('a'));
        self::assertNull((new VaultSearchViewers($this->idMapping(), $this->tokens(new DddUser("ali\u{200B}ce", 'a'))))->forIdentifier('a'));
    }

    public function testAResolverThatAcceptsAUserWithoutIdOrRefusesItsReference(): void
    {
        $accepting = $this->resolver(static fn (): string => throw new AccessDeniedException());
        self::assertNull((new VaultSearchViewers($accepting, $this->tokens(new InMemoryUser('a', null))))->forIdentifier('a'), 'No getId().');
        self::assertNull((new VaultSearchViewers($accepting, $this->tokens(new DddUser('alice', 'a'))))->forIdentifier('a'), 'reference() refuses.');
        self::assertNull((new VaultSearchViewers($this->resolver(static fn (): object => new DddUser('alice', 'a')), $this->tokens(new DddUser('alice', 'a'))))->forIdentifier('a'), 'Id mapping must bind the id string.');
        self::assertNull((new VaultSearchViewers($this->resolver(static fn (): string => 'bob'), $this->tokens(new DddUser('alice', 'a'))))->forIdentifier('a'), 'Id mapping must bind this very id.');
    }

    public function testRelationMappingBindsTheUserObject(): void
    {
        $user    = new DddUser('7', 'a');
        $viewers = new VaultSearchViewers(new DoctrineVaultUserReferenceResolver(DddUser::class, new VaultUserClassPolicy(DddUser::class)), $this->tokens($user));

        self::assertSame($user, $viewers->forIdentifier('a')?->reference);
    }

    private function idMapping(): DoctrineVaultUserReferenceResolver
    {
        return new DoctrineVaultUserReferenceResolver(DddUser::class, new VaultUserClassPolicy(DddUser::class, [], VaultUserMapping::ID), VaultUserMapping::ID);
    }

    private function tokens(UserInterface $user): TokenStorage
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main', []));

        return $tokens;
    }

    /**
     * @param callable(): (object|string) $reference
     */
    private function resolver(callable $reference): VaultUserReferenceResolverInterface
    {
        return new class($reference) implements VaultUserReferenceResolverInterface {
            /** @var callable(): (object|string) */
            private $reference;

            public function __construct(callable $reference)
            {
                $this->reference = $reference;
            }

            public function mapsById(): bool
            {
                return true;
            }

            public function isAccepted(object $user): bool
            {
                return true;
            }

            public function reference(object $user): object|string
            {
                return ($this->reference)();
            }
        };
    }
}

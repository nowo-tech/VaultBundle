<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Port;

use DateTimeImmutable;
use Nowo\VaultBundle\Dto\VaultHealthReport;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Port\VaultAccessCheckerGate;
use Nowo\VaultBundle\Port\VaultHealthReporterGate;
use Nowo\VaultBundle\Port\VaultReauthenticationGate;
use Nowo\VaultBundle\Port\VaultScopeAccessGate;
use Nowo\VaultBundle\Port\VaultTeamMembershipGate;
use Nowo\VaultBundle\Port\VaultUserLocatorGate;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface;
use Nowo\VaultBundle\Security\VaultReauthProof;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\Support\VaultRefUser;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Nowo\VaultBundle\ValueObject\VaultReauthContext;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function hash;

/**
 * The internal gates (1.19.0; since 2.0 they ask only the framework-free ports): the VO each port receives, the answers
 * passed back, and the exact-id rule of the user lookup.
 */
final class VaultGatesTest extends TestCase
{
    #[Test]
    public function theAccessGateAsksThePolicyWithTheUserReference(): void
    {
        $user   = new TestUser('1');
        $policy = $this->createMock(VaultAccessPolicyInterface::class);
        $policy->expects(self::once())->method('allowsAccess')->with(self::callback(static fn (?VaultUserRef $ref): bool => $ref?->user() === $user && $ref->id === '1'))->willReturn(true);
        $policy->expects(self::once())->method('allowsCreate')->with(null)->willReturn(false);
        $policy->expects(self::once())->method('allowsList')->willReturn(true);
        $policy->expects(self::once())->method('allowsRevoke')->willReturn(false);

        $gate = new VaultAccessCheckerGate($policy);
        self::assertTrue($gate->canAccess($user));
        self::assertFalse($gate->canCreate(), 'null: the request token is checked');
        self::assertTrue($gate->canList($user));
        self::assertFalse($gate->canRevoke($user));
    }

    #[Test]
    public function exceptionsOfTheAccessPolicyPropagate(): void
    {
        $policy = $this->createMock(VaultAccessPolicyInterface::class);
        $policy->method('allowsAccess')->willThrowException(new RuntimeException('host down'));

        $this->expectException(RuntimeException::class);
        (new VaultAccessCheckerGate($policy))->canAccess(new TestUser('1'));
    }

    #[Test]
    public function theTeamGateAsksTheProvider(): void
    {
        $user     = new TestUser('2');
        $provider = $this->createMock(VaultTeamMembershipProviderInterface::class);
        $provider->expects(self::once())->method('teamIdsOf')->with(self::callback(static fn (VaultUserRef $ref): bool => $ref->id === '2' && $ref->user() === $user))->willReturn(['team-1', 'team-2']);

        self::assertSame(['team-1', 'team-2'], (new VaultTeamMembershipGate($provider))->getTeamIdsForUser($user));
    }

    #[Test]
    public function theScopeGateAsksTheProvider(): void
    {
        $user     = new TestUser('3');
        $access   = new VaultScopeAccess(VaultPermission::Write, true);
        $provider = $this->createMock(VaultScopeAccessProviderInterface::class);
        $provider->method('scopeAccessOf')->willReturnCallback(static fn (VaultUserRef $ref, string $scope): ?VaultScopeAccess => $ref->id === '3' && $scope === 'org-1' ? $access : null);

        $gate = new VaultScopeAccessGate($provider);
        self::assertSame($access, $gate->resolve($user, 'org-1'));
        self::assertNull($gate->resolve($user, 'org-2'));
    }

    #[Test]
    public function theReauthenticationGateGivesTheProviderAFrameworkFreeContext(): void
    {
        $user    = new TestUser('9');
        $request = Request::create('/tools/vault/export', 'POST');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);
        $request->attributes->set('_route', 'nowo_vault_personal_export');
        $proof    = new VaultReauthProof('p1', '9', VaultReauthProof::PURPOSE_PERSONAL_EXPORT, new DateTimeImmutable(), ['password'], false, 'b');
        $provider = new class($proof) implements VaultReauthenticationProviderInterface {
            public ?VaultReauthContext $seen = null;

            public ?VaultReauthProof $consumed = null;

            public function __construct(private readonly VaultReauthProof $proof)
            {
            }

            public function proofFor(VaultReauthContext $context): VaultReauthProof
            {
                $this->seen = $context;

                return $this->proof;
            }

            public function consumeProof(VaultReauthProof $proof): void
            {
                $this->consumed = $proof;
            }

            public function reauthenticationUrl(VaultReauthContext $context, string $returnTo): string
            {
                return '/reauth?for=' . $context->purpose . '&back=' . $returnTo . '&user=' . ($context->user?->id ?? 'none');
            }
        };

        $gate = new VaultReauthenticationGate($provider);
        self::assertSame($proof, $gate->getProof($user, $request, VaultReauthProof::PURPOSE_PERSONAL_EXPORT));
        self::assertNotNull($provider->seen);
        self::assertSame('9', $provider->seen->user?->id);
        self::assertSame($user, $provider->seen->user?->user());
        self::assertSame('POST', $provider->seen->method);
        self::assertSame('nowo_vault_personal_export', $provider->seen->routeName);
        self::assertSame('/tools/vault/export', $provider->seen->path());
        self::assertSame(hash('sha256', $session->getId()), $provider->seen->sessionBinding());
        $gate->consume($proof);
        self::assertSame($proof, $provider->consumed);
        self::assertSame('/reauth?for=nowo_vault.personal_export&back=/back&user=none', $gate->getReauthenticationUrl($request, VaultReauthProof::PURPOSE_PERSONAL_EXPORT, '/back'));
        self::assertSame('', VaultReauthenticationGate::context(Request::create('/'), 'x', null)->sessionBinding(), 'no session, no binding');
    }

    #[Test]
    public function theLocatorGateTurnsAReferenceIntoTheUserOrTheStandIn(): void
    {
        $user      = new TestUser('3');
        $directory = $this->createMock(VaultUserDirectoryInterface::class);
        $directory->method('findUser')->willReturnMap([
            ['3', new VaultUserRef('3', 'u', [], $user)],
            ['4', VaultUserRef::ofId('4')],
            ['5', null],
            ['a', VaultUserRef::ofId('A')],
            ['b', new VaultUserRef('b', 'u', [], $user)],
        ]);
        $gate = new VaultUserLocatorGate($directory);
        self::assertSame($user, $gate->find('3'));
        self::assertInstanceOf(VaultRefUser::class, $gate->find('4'));
        self::assertNull($gate->find('5'));
        self::assertNull($gate->find('a'), 'NJ-1: an answer for another id (byte for byte) is refused');
        self::assertNull($gate->find('b'), 'a user object with another id is refused');
    }

    #[Test]
    public function theHealthGateAsksTheProvider(): void
    {
        $user   = new TestUser('8');
        $policy = new VaultPasswordPolicy(12, 3, 365, false);
        $report = new VaultHealthReport(VaultOwner::user($user), new DateTimeImmutable(), 0, 0, []);
        $new    = $this->createMock(VaultHealthReportProviderInterface::class);
        $new->expects(self::once())->method('reportForScope')->with(self::callback(static fn (VaultUserRef $ref): bool => $ref->user() === $user), 'org-1', $policy)->willReturn($report);
        $new->expects(self::once())->method('reportForPersonalVault')->willReturn($report);
        $gate = new VaultHealthReporterGate($new);
        self::assertSame($report, $gate->forScope($user, 'org-1', $policy));
        self::assertSame($report, $gate->forPersonalVault($user));
    }
}

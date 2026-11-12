<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Port;

use DateTimeImmutable;
use LogicException;
use Nowo\VaultBundle\Dto\VaultHealthReport;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Port\VaultAccessCheckerGate;
use Nowo\VaultBundle\Port\VaultHealthReporterGate;
use Nowo\VaultBundle\Port\VaultPortMode;
use Nowo\VaultBundle\Port\VaultReauthenticationGate;
use Nowo\VaultBundle\Port\VaultScopeAccessGate;
use Nowo\VaultBundle\Port\VaultTeamMembershipGate;
use Nowo\VaultBundle\Port\VaultUserLocatorGate;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationCheckerInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface;
use Nowo\VaultBundle\Security\VaultReauthProof;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Service\VaultHealthReporterInterface;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\Support\VaultRefUser;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
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
use Symfony\Component\Security\Core\User\UserInterface;

use function hash;

/**
 * 1.19.0 internal gates: which interface each mode asks, the weaker answer when both are asked, the VO each 1.19 port
 * receives, and the wiring checks.
 */
final class VaultGatesTest extends TestCase
{
    #[Test]
    public function theAccessGateAsksTheChosenInterfacesAndBothMustAllow(): void
    {
        $user   = new TestUser('1');
        $legacy = $this->createMock(VaultAccessCheckerInterface::class);
        $policy = $this->createMock(VaultAccessPolicyInterface::class);
        $legacy->method('canAccess')->willReturn(true);
        $legacy->method('canCreate')->willReturn(false);
        $legacy->method('canList')->willReturn(true);
        $legacy->method('canRevoke')->willReturn(true);
        $policy->method('allowsAccess')->willReturnCallback(static fn (?VaultUserRef $ref): bool => $ref?->user() === $user);
        $policy->method('allowsCreate')->willReturn(true);
        $policy->method('allowsList')->willReturn(false);
        $policy->method('allowsRevoke')->willReturn(true);

        $both = new VaultAccessCheckerGate(VaultPortMode::BOTH, $legacy, $policy);
        self::assertTrue($both->canAccess($user));
        self::assertFalse($both->canAccess(), 'the policy gets null for no user');
        self::assertFalse($both->canCreate($user), 'the deprecated port denies');
        self::assertFalse($both->canList($user), 'the 1.19 port denies');
        self::assertTrue($both->canRevoke($user));

        self::assertFalse((new VaultAccessCheckerGate(VaultPortMode::LEGACY, $legacy))->canCreate($user));
        self::assertTrue((new VaultAccessCheckerGate(VaultPortMode::POLICY, null, $policy))->canCreate($user));
    }

    #[Test]
    public function aDenialOfTheDeprecatedPortStopsThereAndExceptionsPropagate(): void
    {
        $legacy = $this->createMock(VaultAccessCheckerInterface::class);
        $legacy->method('canAccess')->willReturn(false);
        $policy = $this->createMock(VaultAccessPolicyInterface::class);
        $policy->expects(self::never())->method('allowsAccess');
        self::assertFalse((new VaultAccessCheckerGate(VaultPortMode::BOTH, $legacy, $policy))->canAccess(new TestUser()));

        $failing = $this->createMock(VaultAccessPolicyInterface::class);
        $failing->method('allowsAccess')->willThrowException(new RuntimeException('host down'));
        $this->expectException(RuntimeException::class);
        (new VaultAccessCheckerGate(VaultPortMode::POLICY, null, $failing))->canAccess(new TestUser());
    }

    #[Test]
    public function aGateWithoutTheServiceItsModeNeedsFailsAtConstruction(): void
    {
        foreach ([[VaultPortMode::LEGACY, null, null], [VaultPortMode::POLICY, null, null], ['other', null, null]] as [$mode, $legacy, $policy]) {
            try {
                new VaultAccessCheckerGate($mode, $legacy, $policy);
                self::fail('mode ' . $mode);
            } catch (LogicException) {
            }
        }
        $this->expectException(LogicException::class);
        new VaultUserLocatorGate(VaultPortMode::BOTH, $this->createMock(VaultUserLocatorInterface::class), $this->createMock(VaultUserDirectoryInterface::class));
    }

    #[Test]
    public function theTeamGateKeepsTheTeamsBothReturn(): void
    {
        $user   = new TestUser('1');
        $legacy = $this->createMock(VaultTeamMembershipResolverInterface::class);
        $legacy->method('getTeamIdsForUser')->willReturn(['a', 'b', 'c']);
        $policy = $this->createMock(VaultTeamMembershipProviderInterface::class);
        $policy->method('teamIdsOf')->willReturnCallback(static fn (VaultUserRef $ref): array => $ref->id === '1' ? ['c', 'a', 'z'] : []);

        self::assertSame(['a', 'c'], (new VaultTeamMembershipGate(VaultPortMode::BOTH, $legacy, $policy))->getTeamIdsForUser($user));
        self::assertSame(['a', 'b', 'c'], (new VaultTeamMembershipGate(VaultPortMode::LEGACY, $legacy))->getTeamIdsForUser($user));
        self::assertSame(['c', 'a', 'z'], (new VaultTeamMembershipGate(VaultPortMode::POLICY, null, $policy))->getTeamIdsForUser($user));

        // NJ-4: int ids from one interface and strings from the other are the same teams.
        $ints = $this->createMock(VaultTeamMembershipResolverInterface::class);
        $ints->method('getTeamIdsForUser')->willReturn([7, 8]);
        $strings = $this->createMock(VaultTeamMembershipProviderInterface::class);
        $strings->method('teamIdsOf')->willReturn(['8', '9']);
        self::assertSame([8], (new VaultTeamMembershipGate(VaultPortMode::BOTH, $ints, $strings))->getTeamIdsForUser($user));
    }

    #[Test]
    public function theScopeGateKeepsTheWeakerStanding(): void
    {
        $user = new TestUser('1');
        $gate = static function (?VaultScopeAccess $left, ?VaultScopeAccess $right): VaultScopeAccessGate {
            $legacy = new class($left) implements VaultScopeAccessResolverInterface {
                public function __construct(private readonly ?VaultScopeAccess $answer)
                {
                }

                public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
                {
                    return $this->answer;
                }
            };
            $policy = new class($right) implements VaultScopeAccessProviderInterface {
                public function __construct(private readonly ?VaultScopeAccess $answer)
                {
                }

                public function scopeAccessOf(VaultUserRef $user, string $scope): ?VaultScopeAccess
                {
                    return $this->answer;
                }
            };

            return new VaultScopeAccessGate(VaultPortMode::BOTH, $legacy, $policy);
        };

        $both = $gate(new VaultScopeAccess(VaultPermission::Admin, true, true, false), new VaultScopeAccess(VaultPermission::Write, true, false, true))->resolve($user, 'org-1');
        self::assertEquals(new VaultScopeAccess(VaultPermission::Write, true, false, false), $both);
        $read = $gate(new VaultScopeAccess(VaultPermission::Read), new VaultScopeAccess(VaultPermission::Admin))->resolve($user, 'org-1');
        self::assertSame(VaultPermission::Read, $read?->baseline);
        self::assertNull($gate(new VaultScopeAccess(null, true), new VaultScopeAccess(VaultPermission::Admin, true))->resolve($user, 'org-1')?->baseline);
        self::assertNull($gate(null, new VaultScopeAccess(VaultPermission::Admin))->resolve($user, 'org-1'));
        self::assertNull($gate(new VaultScopeAccess(VaultPermission::Admin), null)->resolve($user, 'org-1'));
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

        $gate = new VaultReauthenticationGate(VaultPortMode::POLICY, null, $provider);
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

        $legacy = $this->createMock(VaultReauthenticationCheckerInterface::class);
        $legacy->expects(self::once())->method('getProof')->with($user, $request, 'x')->willReturn(null);
        $legacy->expects(self::once())->method('consume')->with($proof);
        $legacy->expects(self::once())->method('getReauthenticationUrl')->with($request, 'x', '/r')->willReturn('/legacy');
        $legacyGate = new VaultReauthenticationGate(VaultPortMode::LEGACY, $legacy);
        self::assertNull($legacyGate->getProof($user, $request, 'x'));
        $legacyGate->consume($proof);
        self::assertSame('/legacy', $legacyGate->getReauthenticationUrl($request, 'x', '/r'));
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
        $gate = new VaultUserLocatorGate(VaultPortMode::POLICY, null, $directory);
        self::assertSame($user, $gate->find('3'));
        self::assertInstanceOf(VaultRefUser::class, $gate->find('4'));
        self::assertNull($gate->find('5'));
        self::assertNull($gate->find('a'), 'NJ-1: an answer for another id (byte for byte) is refused');
        self::assertNull($gate->find('b'), 'a user object with another id is refused');

        $legacy = $this->createMock(VaultUserLocatorInterface::class);
        $legacy->method('find')->willReturn($user);
        self::assertSame($user, (new VaultUserLocatorGate(VaultPortMode::LEGACY, $legacy))->find('x'));
    }

    #[Test]
    public function theHealthGateAsksTheChosenInterface(): void
    {
        $user   = new TestUser('8');
        $policy = new VaultPasswordPolicy(12, 3, 365, false);
        $report = new VaultHealthReport(VaultOwner::user($user), new DateTimeImmutable(), 0, 0, []);
        $new    = $this->createMock(VaultHealthReportProviderInterface::class);
        $new->expects(self::once())->method('reportForScope')->with(self::callback(static fn (VaultUserRef $ref): bool => $ref->user() === $user), 'org-1', $policy)->willReturn($report);
        $new->expects(self::once())->method('reportForPersonalVault')->willReturn($report);
        $gate = new VaultHealthReporterGate(VaultPortMode::POLICY, null, $new);
        self::assertSame($report, $gate->forScope($user, 'org-1', $policy));
        self::assertSame($report, $gate->forPersonalVault($user));

        $legacy = $this->createMock(VaultHealthReporterInterface::class);
        $legacy->expects(self::once())->method('forScope')->willReturn($report);
        $legacy->expects(self::once())->method('forPersonalVault')->willReturn($report);
        $legacyGate = new VaultHealthReporterGate(VaultPortMode::LEGACY, $legacy);
        self::assertSame($report, $legacyGate->forScope($user, 'org-1'));
        self::assertSame($report, $legacyGate->forPersonalVault($user));
    }
}

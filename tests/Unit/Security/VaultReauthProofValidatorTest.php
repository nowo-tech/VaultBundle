<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use LogicException;
use Nowo\VaultBundle\Security\VaultReauthProof;
use Nowo\VaultBundle\Security\VaultReauthProofValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function hash;
use function print_r;
use function serialize;

/**
 * Re-authentication proof checks (1.16.0 §9.2): the bundle's clock, factors, session binding and single use.
 */
final class VaultReauthProofValidatorTest extends TestCase
{
    private const PURPOSE = VaultReauthProof::PURPOSE_PERSONAL_EXPORT;

    private MockClock $clock;
    private Request $request;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-05 12:00:00');
        $session     = new Session(new MockArraySessionStorage());
        $session->setId('session-1');
        $this->request = new Request();
        $this->request->setSession($session);
    }

    public function testAFreshProofPassesOnceWithTheReplayList(): void
    {
        $validator = $this->validator(new ArrayAdapter());
        $proof     = $this->proof();
        self::assertNull($validator->validate($proof, '7', self::PURPOSE, $this->request));

        $validator->markConsumed($proof);
        self::assertSame(VaultReauthProofValidator::REASON_REPLAYED, $validator->validate($proof, '7', self::PURPOSE, $this->request));
        self::assertNull($validator->validate($this->proof(id: 'other'), '7', self::PURPOSE, $this->request));
    }

    public function testEveryFailureHasItsReason(): void
    {
        $validator = $this->validator();
        $cases     = [
            [null, '7', VaultReauthProofValidator::REASON_MISSING],
            [$this->proof(), null, VaultReauthProofValidator::REASON_MISSING],
            [$this->proof(), '8', VaultReauthProofValidator::REASON_MISSING],
            [$this->proof(purpose: 'app.other'), '7', VaultReauthProofValidator::REASON_MISSING],
            [$this->proof(age: 301), '7', VaultReauthProofValidator::REASON_STALE],
            [$this->proof(age: -31), '7', VaultReauthProofValidator::REASON_STALE],
            [$this->proof(factors: ['totp']), '7', VaultReauthProofValidator::REASON_PRIMARY_MISSING],
            [$this->proof(mfa: true), '7', VaultReauthProofValidator::REASON_MFA_MISSING],
            [$this->proof(factors: ['password', 'recovery_code'], mfa: true), '7', VaultReauthProofValidator::REASON_MFA_MISSING],
            [$this->proof(binding: hash('sha256', 'session-2')), '7', VaultReauthProofValidator::REASON_SESSION_MISMATCH],
        ];
        foreach ($cases as $i => [$proof, $userId, $reason]) {
            self::assertSame($reason, $validator->validate($proof, $userId, self::PURPOSE, $this->request), (string) $i);
        }

        // The limits themselves pass; without a pool, markConsumed() is a no-op.
        foreach ([$this->proof(age: 300), $this->proof(age: -30), $this->proof(factors: ['password', 'webauthn'], mfa: true)] as $proof) {
            self::assertNull($validator->validate($proof, '7', self::PURPOSE, $this->request));
        }
        $validator->markConsumed($this->proof());
        self::assertNull($validator->validate($this->proof(), '7', self::PURPOSE, $this->request));

        // No session at all: never bound.
        self::assertSame(VaultReauthProofValidator::REASON_SESSION_MISMATCH, $validator->validate($this->proof(), '7', self::PURPOSE, new Request()));
    }

    public function testConfiguredFactorsAndMaxAge(): void
    {
        $validator = new VaultReauthProofValidator(60, ['sso_reauth'], ['webauthn'], null, $this->clock);
        self::assertNull($validator->validate($this->proof(factors: ['sso_reauth', 'webauthn'], mfa: true), '7', self::PURPOSE, $this->request));
        self::assertSame(VaultReauthProofValidator::REASON_PRIMARY_MISSING, $validator->validate($this->proof(), '7', self::PURPOSE, $this->request));
        self::assertSame(VaultReauthProofValidator::REASON_MFA_MISSING, $validator->validate($this->proof(factors: ['sso_reauth', 'totp'], mfa: true), '7', self::PURPOSE, $this->request));
        self::assertSame(VaultReauthProofValidator::REASON_STALE, $validator->validate($this->proof(age: 61, factors: ['sso_reauth']), '7', self::PURPOSE, $this->request));
        // Default clock: the real time.
        self::assertSame(VaultReauthProofValidator::REASON_STALE, (new VaultReauthProofValidator())->validate($this->proof(), '7', self::PURPOSE, $this->request));
    }

    public function testTheProofNeverDumpsItsSecretsAndRefusesSerialization(): void
    {
        $proof = $this->proof(id: 'proof-secret-id');
        self::assertStringNotContainsString('proof-secret-id', print_r($proof, true));
        self::assertStringNotContainsString(hash('sha256', 'session-1'), print_r($proof, true));
        self::assertSame(['password'], $proof->__debugInfo()['factors']);

        $this->expectException(LogicException::class);
        serialize($proof);
    }

    private function validator(?ArrayAdapter $pool = null): VaultReauthProofValidator
    {
        return new VaultReauthProofValidator(300, ['password'], ['totp', 'webauthn'], $pool, $this->clock);
    }

    /**
     * @param list<string> $factors
     */
    private function proof(int $age = 10, array $factors = ['password'], bool $mfa = false, ?string $binding = null, string $purpose = self::PURPOSE, string $id = 'p1'): VaultReauthProof
    {
        return new VaultReauthProof($id, '7', $purpose, $this->clock->now()->modify(-$age . ' seconds'), $factors, $mfa, $binding ?? hash('sha256', 'session-1'));
    }
}

<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

use function array_intersect;
use function hash;
use function hash_equals;

/**
 * Checks a host re-authentication proof against the bundle's own clock and rules (1.16.0, contract §9.2); it never
 * trusts a host boolean. Returns a reason code (logged, never shown) or null when the proof is acceptable:
 *
 * 1. a proof for the current user and purpose (`reauth_missing`);
 * 2. `authenticatedAt` within `export.reauth_max_age` and at most 30 s in the future (`reauth_stale`);
 * 3. an accepted primary factor (`primary_factor_missing`);
 * 4. with MFA enrolled, an accepted second factor (`mfa_missing`; recovery codes are not accepted by default);
 * 5. the session binding equals `sha256(current session id)` (`session_mismatch`);
 * 6. the proof id was not used before (`reauth_replayed`): besides the host's consume(), the bundle remembers consumed
 *    proof ids in its cache pool for the freshness window.
 */
final readonly class VaultReauthProofValidator
{
    public const REASON_MISSING          = 'reauth_missing';
    public const REASON_STALE            = 'reauth_stale';
    public const REASON_PRIMARY_MISSING  = 'primary_factor_missing';
    public const REASON_MFA_MISSING      = 'mfa_missing';
    public const REASON_SESSION_MISMATCH = 'session_mismatch';
    public const REASON_REPLAYED         = 'reauth_replayed';

    /** Clock skew tolerated on `authenticatedAt` (seconds in the future). */
    public const MAX_CLOCK_SKEW = 30;

    /**
     * @param list<string> $acceptedPrimaryFactors export.accepted_primary_factors
     * @param list<string> $acceptedMfaFactors export.accepted_mfa_factors
     */
    public function __construct(
        private int $maxAge = 300,
        private array $acceptedPrimaryFactors = ['password'],
        private array $acceptedMfaFactors = ['totp', 'webauthn'],
        private ?CacheItemPoolInterface $consumedProofs = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Null when the proof may be used now, else the reason code.
     */
    public function validate(?VaultReauthProof $proof, ?string $userId, string $purpose, Request $request): ?string
    {
        if (!$proof instanceof VaultReauthProof || $userId === null || !hash_equals($userId, $proof->userId) || $proof->purpose !== $purpose) {
            return self::REASON_MISSING;
        }

        $now = $this->now();
        $at  = $proof->authenticatedAt->getTimestamp();
        if ($at < $now->getTimestamp() - $this->maxAge || $at > $now->getTimestamp() + self::MAX_CLOCK_SKEW) {
            return self::REASON_STALE;
        }

        if (array_intersect($proof->factors, $this->acceptedPrimaryFactors) === []) {
            return self::REASON_PRIMARY_MISSING;
        }

        if ($proof->mfaEnrolled && array_intersect($proof->factors, $this->acceptedMfaFactors) === []) {
            return self::REASON_MFA_MISSING;
        }

        $session = $request->hasSession() ? $request->getSession()->getId() : '';
        if ($session === '' || !hash_equals($proof->sessionBinding, hash('sha256', $session))) {
            return self::REASON_SESSION_MISMATCH;
        }

        if ($this->consumedProofs instanceof CacheItemPoolInterface && $this->consumedProofs->getItem($this->key($proof))->isHit()) {
            return self::REASON_REPLAYED;
        }

        return null;
    }

    /**
     * Remembers the proof id as used (until it would be stale anyway).
     */
    public function markConsumed(VaultReauthProof $proof): void
    {
        if (!$this->consumedProofs instanceof CacheItemPoolInterface) {
            return;
        }

        $item = $this->consumedProofs->getItem($this->key($proof));
        $item->set(true);
        $item->expiresAfter($this->maxAge + self::MAX_CLOCK_SKEW);
        $this->consumedProofs->save($item);
    }

    private function key(VaultReauthProof $proof): string
    {
        return 'nowo_vault_reauth_' . hash('sha256', $proof->proofId);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}

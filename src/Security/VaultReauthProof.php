<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use DateTimeImmutable;
use LogicException;

/**
 * A fresh interactive re-authentication reported by the host (1.16.0, contract §9.1). The bundle checks every field
 * itself ({@see VaultReauthProofValidator}); the proof is single use. Never logged, never serialized.
 */
final readonly class VaultReauthProof
{
    /** Purpose of the decrypted personal export. */
    public const PURPOSE_PERSONAL_EXPORT = 'nowo_vault.personal_export';

    /**
     * @param string $proofId opaque id of this re-authentication (unique; used to refuse a replay)
     * @param string $userId id of the re-authenticated user
     * @param string $purpose what the re-authentication was for ({@see self::PURPOSE_PERSONAL_EXPORT})
     * @param DateTimeImmutable $authenticatedAt when the factors were verified in the re-auth step
     * @param list<string> $factors factors verified in the re-auth step only (e.g. `password`, `totp`, `webauthn`)
     * @param bool $mfaEnrolled whether the user has any second factor enrolled now (read from the store)
     * @param string $sessionBinding `hash('sha256', session id)` at re-auth time
     */
    public function __construct(
        public string $proofId,
        public string $userId,
        public string $purpose,
        public DateTimeImmutable $authenticatedAt,
        public array $factors,
        public bool $mfaEnrolled,
        public string $sessionBinding,
    ) {
    }

    /**
     * Debug output without the proof id or the session binding.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'purpose' => $this->purpose, 'factors' => $this->factors, 'mfaEnrolled' => $this->mfaEnrolled, 'proofId' => '***', 'sessionBinding' => '***'];
    }

    /**
     * A proof is never serialized (sessions, caches, logs).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A re-authentication proof cannot be serialized.');
    }
}

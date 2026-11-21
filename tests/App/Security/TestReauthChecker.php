<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Security;

use Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface;
use Nowo\VaultBundle\Security\VaultReauthProof;
use Nowo\VaultBundle\ValueObject\VaultReauthContext;

use function in_array;
use function rawurlencode;

/**
 * Re-authentication hook of the scopes test kernel: the proof is a static the tests set (it survives the kernel
 * reboots between requests). `$honest = false` keeps returning consumed proofs (a buggy host), so the bundle's own
 * replay list is exercised.
 */
final class TestReauthChecker implements VaultReauthenticationProviderInterface
{
    public static ?VaultReauthProof $proof = null;

    /** @var list<string> */
    public static array $consumed = [];

    public static bool $honest = true;

    public static function reset(): void
    {
        self::$proof    = null;
        self::$consumed = [];
        self::$honest   = true;
    }

    public function proofFor(VaultReauthContext $context): ?VaultReauthProof
    {
        $proof = self::$proof;
        if (!$proof instanceof VaultReauthProof || (self::$honest && in_array($proof->proofId, self::$consumed, true))) {
            return null;
        }

        return $proof;
    }

    public function consumeProof(VaultReauthProof $proof): void
    {
        self::$consumed[] = $proof->proofId;
    }

    public function reauthenticationUrl(VaultReauthContext $context, string $returnTo): string
    {
        return '/reauth?purpose=' . rawurlencode($context->purpose) . '&return=' . rawurlencode($returnTo);
    }
}

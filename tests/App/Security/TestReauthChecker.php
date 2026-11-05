<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Security;

use Nowo\VaultBundle\Security\VaultReauthenticationCheckerInterface;
use Nowo\VaultBundle\Security\VaultReauthProof;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;
use function rawurlencode;

/**
 * Re-authentication hook of the scopes test kernel: the proof is a static the tests set (it survives the kernel
 * reboots between requests). `$honest = false` keeps returning consumed proofs (a buggy host), so the bundle's own
 * replay list is exercised.
 */
final class TestReauthChecker implements VaultReauthenticationCheckerInterface
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

    public function getProof(UserInterface $user, Request $request, string $purpose): ?VaultReauthProof
    {
        $proof = self::$proof;
        if (!$proof instanceof VaultReauthProof || (self::$honest && in_array($proof->proofId, self::$consumed, true))) {
            return null;
        }

        return $proof;
    }

    public function consume(VaultReauthProof $proof): void
    {
        self::$consumed[] = $proof->proofId;
    }

    public function getReauthenticationUrl(Request $request, string $purpose, string $returnTo): string
    {
        return '/reauth?purpose=' . rawurlencode($purpose) . '&return=' . rawurlencode($returnTo);
    }
}

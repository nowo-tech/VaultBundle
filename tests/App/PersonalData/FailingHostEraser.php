<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\PersonalData;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use RuntimeException;

/**
 * A host eraser that runs after the vault's (priority 0) and fails when the context asks for it, so UserKit rolls the
 * whole erasure back (2.5.0 tests of the joined transaction).
 */
final class FailingHostEraser implements PersonalDataEraserInterface
{
    public function getKey(): string
    {
        return 'host_identity';
    }

    public function supports(object $user): bool
    {
        return true;
    }

    public function erase(ErasureRequest $request): void
    {
        if (($request->context['fail'] ?? false) === true) {
            throw new RuntimeException('host eraser failed');
        }
    }
}

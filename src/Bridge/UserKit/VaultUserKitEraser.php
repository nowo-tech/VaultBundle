<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Bridge\UserKit;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\VaultBundle\Service\VaultPersonalDataEraserInterface;
use Nowo\VaultBundle\Support\UserIdResolver;

use function trim;

/**
 * UserKit personal-data eraser `vault` (2.5.0, Beacon spec 185 FR-023): erases the user's vault rows through
 * {@see VaultPersonalDataEraserInterface::erase()} with the string form of the user's `getId()` (the id the vault
 * stores). Registered by the bundle when nowo-tech/user-kit-bundle is installed and `personal_data.user_kit` is true
 * (the default), at priority {@see self::PRIORITY}: before the host identity eraser, while the user row still exists
 * (relation mapping) and its id is unchanged.
 *
 * UserKit runs every eraser in one transaction: on the vault's connection the erasure joins it (no transaction or
 * savepoint of its own), so UserKit's commit or rollback decides for all of it. No flush, no state (worker mode).
 * The matching export section is {@see VaultUserKitExportProvider}.
 */
final readonly class VaultUserKitEraser implements PersonalDataEraserInterface
{
    /** Key in UserKit's erasure result. */
    public const KEY = 'vault';

    /** Tag priority: higher runs first; host identity erasers run at 100 or below. */
    public const PRIORITY = 180;

    public function __construct(
        private VaultPersonalDataEraserInterface $eraser,
        private int $batchSize = 500,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * Any user with a non-empty id (a user the vault never saw simply has no rows).
     */
    public function supports(object $user): bool
    {
        return self::userId($user) !== null;
    }

    public function erase(ErasureRequest $request): void
    {
        $userId = self::userId($request->user);
        if ($userId === null) {
            return;
        }

        $this->eraser->erase($userId, $this->batchSize);
    }

    /**
     * The vault user id of a host user: the string form of `getId()`, as is (never trimmed), or null when blank.
     *
     * @internal
     */
    public static function userId(object $user): ?string
    {
        $id = UserIdResolver::getId($user);

        return $id !== null && trim($id) !== '' ? $id : null;
    }
}

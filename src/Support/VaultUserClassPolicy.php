<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\ValueObject\VaultUserId;
use Stringable;

use function is_int;
use function is_numeric;
use function is_string;
use function ltrim;
use function method_exists;
use function strlen;
use function trim;

/**
 * Which users the vault maps to a creator / owner by their `getId()` (1.17.0).
 *
 * `nowo_vault.user_class` instances always are, and so is a {@see VaultUserId} (what the entities return with the id
 * mapping). Another class is only when listed in `nowo_vault.accepted_user_classes` (a security user that is no
 * Doctrine entity, a wrapper or a read model carrying the `user_class` id). With the default empty list, a user of
 * another identity domain (Admin#5 next to Customer#5) is never matched with a vault owner: those paths fail closed.
 * Ids of 0, negative ids and empty ids are refused.
 */
final readonly class VaultUserClassPolicy
{
    /** @var list<string> */
    private array $acceptedUserClasses;

    /**
     * @param string $userClass `nowo_vault.user_class`
     * @param list<string> $acceptedUserClasses `nowo_vault.accepted_user_classes`
     */
    public function __construct(
        private string $userClass,
        array $acceptedUserClasses = [],
    ) {
        $classes = [];
        foreach ($acceptedUserClasses as $class) {
            $classes[] = ltrim($class, '\\');
        }
        $this->acceptedUserClasses = $classes;
    }

    /**
     * Whether the user is a `user_class` instance.
     */
    public function isUserClass(object $user): bool
    {
        $class = ltrim($this->userClass, '\\');

        return $user instanceof $class;
    }

    /**
     * Whether the user is a `user_class` instance, a {@see VaultUserId} or an instance of a listed accepted class.
     */
    public function isAccepted(object $user): bool
    {
        if ($user instanceof VaultUserId || $this->isUserClass($user)) {
            return true;
        }

        foreach ($this->acceptedUserClasses as $class) {
            if ($user instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw `getId()` of an accepted user when it is a usable id, else null.
     */
    public function idOf(object $user): int|string|Stringable|null
    {
        if (!$this->isAccepted($user) || !method_exists($user, 'getId')) {
            return null;
        }

        $id = $user->getId();

        return self::isUsableId($id) ? $id : null;
    }

    /**
     * Whether an id value can identify a user: a positive int, or a non-empty string / Stringable of at most
     * {@see VaultMetadataListener::USER_ID_LENGTH} bytes, without leading or trailing whitespace (1.17.0, LQ-V-I2: the
     * id is stored and compared byte for byte, never normalised), that is not a numeric value ≤ 0.
     */
    public static function isUsableId(mixed $id): bool
    {
        if (is_int($id)) {
            return $id > 0;
        }
        if (!is_string($id) && !$id instanceof Stringable) {
            return false;
        }
        $value = (string) $id;

        return $value !== ''
            && trim($value) === $value
            && strlen($value) <= VaultMetadataListener::USER_ID_LENGTH
            && (!is_numeric($value) || (float) $value > 0);
    }
}

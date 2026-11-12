<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\ValueObject\VaultUserId;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Stringable;

use function is_int;
use function is_numeric;
use function is_string;
use function ltrim;
use function method_exists;
use function preg_match;
use function strlen;
use function trim;

/**
 * Which users the vault maps to a creator / owner by their `getId()` (1.17.0).
 *
 * `nowo_vault.user_class` instances always are, and so are a {@see VaultUserId} (what the entities return with the id
 * mapping) and a {@see VaultRefUser} (a {@see VaultUserRef} your code gave by id, 1.19.0). Another class is only when listed in `nowo_vault.accepted_user_classes` (a security user that is no
 * Doctrine entity, a wrapper or a read model carrying the `user_class` id). With the default empty list, a user of
 * another identity domain (Admin#5 next to Customer#5) is never matched with a vault owner: those paths fail closed.
 * Ids of 0, negative ids and empty ids are refused.
 */
final readonly class VaultUserClassPolicy
{
    /**
     * Characters an id must not contain: controls, format characters (zero-width, bidi), separators (spaces) and the
     * other default-ignorable code points (combining grapheme joiner, Hangul fillers, Khmer / Mongolian inherent marks,
     * variation selectors). With `u`, invalid UTF-8 makes preg_match() return false, which refuses the id as well.
     */
    private const INVISIBLE = '/[\p{Cc}\p{Cf}\p{Z}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}]/u';

    /** @var list<string> */
    private array $acceptedUserClasses;

    /**
     * @param string $userClass `nowo_vault.user_class`
     * @param list<string> $acceptedUserClasses `nowo_vault.accepted_user_classes`
     * @param string $userMapping `nowo_vault.user_mapping`: the strict id rules apply with `id` only
     */
    public function __construct(
        private string $userClass,
        array $acceptedUserClasses = [],
        private string $userMapping = VaultUserMapping::RELATION,
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
     * Whether the user is a `user_class` instance, a {@see VaultUserId}, the vault's stand-in for a {@see VaultUserRef}
     * your code gave by id ({@see VaultRefUser}, 1.19.0) or an instance of a listed accepted class.
     */
    public function isAccepted(object $user): bool
    {
        if ($user instanceof VaultUserId || $user instanceof VaultRefUser || $this->isUserClass($user)) {
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

        return self::isUsableId($id, $this->userMapping === VaultUserMapping::ID) ? $id : null;
    }

    /**
     * Whether an id value can identify a user: a positive int, or a string / Stringable that is not blank and not a
     * numeric value ≤ 0 (the relation mapping, unchanged).
     *
     * `$strict` (the id mapping, 1.17.0; the id is stored and compared byte for byte, never normalised) also requires
     * at most {@see VaultMetadataListener::USER_ID_LENGTH} bytes and no leading or trailing whitespace (LQ-V-I2), and
     * valid UTF-8 without control, format (zero-width: U+200B, U+200D, U+FEFF, bidi marks…), separator (any space) or
     * other default-ignorable characters (LQ-V-P2-M1): collations ignore those, so `Us\u{200B}erA` could match `UserA`
     * in SQL. Such users are refused, never normalised.
     */
    public static function isUsableId(mixed $id, bool $strict = false): bool
    {
        if (is_int($id)) {
            return $id > 0;
        }
        if (!is_string($id) && !$id instanceof Stringable) {
            return false;
        }
        $value = (string) $id;
        if ($strict && (trim($value) !== $value || strlen($value) > VaultMetadataListener::USER_ID_LENGTH || preg_match(self::INVISIBLE, $value) !== 0)) {
            return false;
        }
        $value = trim($value);

        return $value !== '' && (!is_numeric($value) || (float) $value > 0);
    }
}

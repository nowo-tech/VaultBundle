<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Internal;

use Nowo\VaultBundle\ValueObject\VaultReauthContext;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use WeakMap;

/**
 * Keeps the sensitive values of the framework-free port VOs ({@see VaultUserRef}, {@see VaultReauthContext}, 1.19.0)
 * outside their properties, so no dump of a VO (VarDumper / `dump()`, `var_dump()`, `var_export()`, `print_r()`,
 * `(array)` cast, `json_encode()`, `serialize()`) can show the host user object (password hash, personal fields), the
 * request path or the session binding. A `clone` or an unserialized copy carries none of them.
 *
 * Keyed by the VO in a {@see WeakMap}: an entry disappears with its VO, so nothing outlives the request in a FrankenPHP
 * worker with kernel reset false (no reset needed, REQ-RUNTIME-002).
 *
 * @internal
 */
final class HiddenValues
{
    /** @var WeakMap<object, array<string, array<string, mixed>>>|null */
    private static ?WeakMap $values = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (WeakMap keyed by the per-request VO)

    /**
     * Stores the hidden values of a VO, grouped by bag (`user`, `request`).
     *
     * @param array<string, array<string, mixed>> $bags bag name => (name => value)
     */
    public static function put(object $owner, array $bags): void
    {
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values[$owner] = $bags; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /** One value of a bag, or null when it is absent (or after `clone` / `unserialize()`). */
    public static function get(object $owner, string $bag, string $name): mixed
    {
        return self::$values[$owner][$bag][$name] ?? null;
    }
}

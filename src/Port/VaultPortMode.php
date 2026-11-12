<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use LogicException;

use function in_array;
use function sprintf;

/**
 * Which interface(s) of a host port the bundle asks (1.19.0, chosen at build time by
 * {@see \Nowo\VaultBundle\DependencyInjection\Compiler\VaultPortsPass}).
 *
 * @internal
 */
final class VaultPortMode
{
    /** Only the 1.19 framework-free interface. */
    public const POLICY = 'policy';

    /** Only the deprecated interface, exactly as in 1.18. */
    public const LEGACY = 'legacy';

    /** Both: the weaker answer wins (access ports only; fail closed). */
    public const BOTH = 'both';

    /**
     * @throws LogicException when the mode is unknown or a service it needs is missing
     */
    public static function assert(string $mode, ?object $legacy, ?object $policy, string $port): void
    {
        if (!in_array($mode, [self::POLICY, self::LEGACY, self::BOTH], true)) {
            throw new LogicException(sprintf('Unknown vault port mode "%s" for %s.', $mode, $port));
        }
        if ($mode !== self::POLICY && $legacy === null) {
            throw new LogicException(sprintf('The vault port %s asks the deprecated interface ("%s" mode) but none is wired.', $port, $mode));
        }
        if ($mode !== self::LEGACY && $policy === null) {
            throw new LogicException(sprintf('The vault port %s asks the 1.19 interface ("%s" mode) but none is wired.', $port, $mode));
        }
    }
}

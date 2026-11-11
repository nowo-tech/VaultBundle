<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Worker;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Replaces `services_resetter` in the worker kernel: FrankenPHP worker mode with `FRANKENPHP_RESET_KERNEL=false`
 * never runs `kernel.reset` between requests, so nothing tagged `kernel.reset` (bundle memos, Doctrine identity map,
 * token storage) is reset. Counts the calls the kernel makes, to prove they reset nothing.
 */
final class NoopServicesResetter implements ResetInterface
{
    public static int $calls = 0;

    public function reset(): void
    {
        ++self::$calls;
    }
}

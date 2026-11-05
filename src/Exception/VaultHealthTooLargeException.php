<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Exception;

use RuntimeException;

/**
 * The owner has more login items than `health.max_items` (1.16.0): the on-demand report is refused (413 on the route).
 */
final class VaultHealthTooLargeException extends RuntimeException
{
    /**
     * Creates the exception with the counts (no title, no id).
     */
    public function __construct(int $count, int $max)
    {
        parent::__construct('Vault health report refused: ' . $count . ' login items, the limit is ' . $max . '.');
    }
}

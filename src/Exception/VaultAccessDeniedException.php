<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Exception;

use RuntimeException;

/**
 * The user may not use a scope feature (create in a scope, catalogue, health) (1.16.0). Controllers answer it with
 * the configured `security.deny_status`; the message never names the scope's content.
 */
final class VaultAccessDeniedException extends RuntimeException
{
    /**
     * Creates the exception with a generic message.
     */
    public function __construct(string $message = 'Vault access denied.')
    {
        parent::__construct($message);
    }
}

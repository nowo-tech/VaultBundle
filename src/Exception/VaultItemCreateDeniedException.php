<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Exception;

use Nowo\VaultBundle\Event\VaultItemCreateCheckEvent;
use RuntimeException;

/**
 * A listener of ITEM_CREATE_CHECK refused a new item (2.5.0, e.g. the plan's item limit): nothing was written. The
 * vault pages show the form again with a generic message (HTTP 422, the form is invalid); host calls of the create
 * commands receive it.
 * The message is fixed; {@see getReason()} holds the listener's code ({@see VaultItemCreateCheckEvent::deny()}).
 */
final class VaultItemCreateDeniedException extends RuntimeException
{
    public function __construct(
        private readonly string $reason,
    ) {
        parent::__construct('The new vault item was refused.');
    }

    /**
     * The refusal code given by the listener (e.g. `plan_limit`).
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}

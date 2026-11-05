<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Exception;

use DomainException;

/**
 * Raised for an invalid owner (a malformed scope key, a folder or tag of another owner, a personal-only API called on
 * a scope row). The message never contains titles or payload data.
 */
final class VaultOwnerException extends DomainException
{
}

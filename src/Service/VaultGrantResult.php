<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

/**
 * Outcome of {@see VaultGrantService::grantAs()} (1.16.0).
 */
enum VaultGrantResult: string
{
    /** A new grant row was created (GRANT_CREATED). */
    case Created = 'created';

    /** The existing grant got a new permission and / or expiry (GRANT_UPDATED). */
    case Updated = 'updated';

    /** Already covered by a permanent grant: nothing written, no event. */
    case Unchanged = 'unchanged';
}

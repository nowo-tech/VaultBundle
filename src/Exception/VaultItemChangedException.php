<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Exception;

use DomainException;

/**
 * 1.20 (REQ-RUNTIME-003 rule 4): the item was saved by someone else after the edit page was opened, so this save would
 * overwrite that change. Nothing was saved. The manage UI answers 409 with the domain code {@see self::CODE} in
 * `X-Error-Code` (never a `CONFLICT_*` code: this is not a lock conflict) and shows the current item.
 */
final class VaultItemChangedException extends DomainException
{
    public const CODE = 'VAULT_ITEM_CHANGED';

    public function __construct()
    {
        parent::__construct('The vault item was changed by someone else since the edit page was opened.');
    }
}

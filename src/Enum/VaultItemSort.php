<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Enum;

/**
 * Order of a {@see \Nowo\VaultBundle\Service\VaultItemQueryInterface} query (2.1.0). Ties are broken by the item id,
 * so pages are stable.
 */
enum VaultItemSort: string
{
    /** Last change first (the manage list's order; the default). */
    case UpdatedDesc = 'updated_desc';

    /** Oldest first. */
    case CreatedAsc = 'created_asc';

    /** Newest first. */
    case CreatedDesc = 'created_desc';

    /** Title, A to Z (as the database orders it). */
    case TitleAsc = 'title_asc';
}

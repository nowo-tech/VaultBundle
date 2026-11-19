<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Nowo\VaultBundle\Entity\VaultItem;

use function hash;
use function implode;
use function sort;

/**
 * 1.20 (REQ-RUNTIME-003 rule 4): the edit token of a vault item, carried by the edit form and compared when it is
 * submitted, so that a save never silently overwrites a change someone else saved after the page was opened.
 *
 * A SHA-256 of what the edit form can change: the ciphertext (a new random nonce on every save, so any content save
 * changes it), the title, the folder, the catalogue visibility and the tag names. No schema column is needed. It
 * reveals nothing: the ciphertext is already encrypted, and the hash covers values the page shows anyway. A key
 * rotation (new ciphertexts) also changes it: a form opened before the rotation is refused once and shown again.
 */
final class VaultItemEditToken
{
    /**
     * The token of the item as it is now.
     */
    public static function of(VaultItem $item): string
    {
        $tags = $item->getTagNames();
        sort($tags);

        return hash('sha256', implode("\0", [
            $item->getCiphertext(),
            $item->getTitle(),
            $item->getFolder()?->getId() ?? '',
            $item->isCatalogueHidden() ? '1' : '0',
            implode("\1", $tags),
        ]));
    }
}

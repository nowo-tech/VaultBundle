<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Internal;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Service\VaultGrantResult;
use Nowo\VaultBundle\Support\VaultItemEditToken;
use Nowo\VaultBundle\View\VaultFolderView;
use Nowo\VaultBundle\View\VaultGrantView;
use Nowo\VaultBundle\View\VaultItemView;

use function array_filter;
use function array_values;
use function sort;

/**
 * Builds the 2.1 read models from the vault entities (the views never reference an entity). Stateless.
 *
 * @internal
 */
final class VaultViews
{
    /**
     * The view of an item: metadata only, never the payload or the ciphertext (the edit token is a hash of what the
     * edit form changes, see {@see VaultItemEditToken}).
     */
    public static function item(VaultItem $item): VaultItemView
    {
        // getTagNames() gives [''] for an item without tags (the edit form's empty row).
        $tags = array_values(array_filter($item->getTagNames(), static fn (string $name): bool => $name !== ''));
        sort($tags);

        return new VaultItemView(
            $item->getId(),
            $item->getItemType(),
            $item->getTitle(),
            $item->getOwner()->key(),
            $item->getCreatorId(),
            $item->getOwnerScope(),
            $item->getFolder()?->getId(),
            $tags,
            $item->getCreatedById(),
            $item->getCreatedAt(),
            $item->getUpdatedAt(),
            $item->getSecretChangedAt(),
            $item->getDeletedAt(),
            $item->isCatalogueHidden(),
            VaultItemEditToken::of($item),
        );
    }

    /**
     * The view of a folder.
     */
    public static function folder(VaultFolder $folder): VaultFolderView
    {
        return new VaultFolderView(
            $folder->getId(),
            $folder->getName(),
            $folder->getOwner()->key(),
            $folder->getCreatorId(),
            $folder->getOwnerScope(),
            $folder->getParent()?->getId(),
            $folder->getCreatedAt(),
            $folder->getUpdatedAt(),
            $folder->getDeletedAt(),
        );
    }

    /**
     * The view of a grant, with the outcome of the call that returned it (null in a listing).
     */
    public static function grant(VaultGrant $grant, ?VaultGrantResult $result = null): VaultGrantView
    {
        return new VaultGrantView(
            $grant->getId(),
            $grant->getResourceType(),
            $grant->getResourceId(),
            $grant->getGranteeType(),
            $grant->getGranteeId(),
            $grant->getPermission(),
            $grant->getExpiresAt(),
            $grant->getCreatedById(),
            $grant->getCreatedAt(),
            $result,
        );
    }
}

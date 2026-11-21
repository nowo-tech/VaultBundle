<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use InvalidArgumentException;
use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Enum\VaultItemSort;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultTrashFilter;
use Nowo\VaultBundle\Service\VaultItemQueryInterface;
use Nowo\VaultBundle\ValueObject\VaultOwner;

use function array_key_exists;
use function mb_strlen;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * What a {@see VaultItemQueryInterface} query looks for (2.1.0, REQ-ARCH-001 rule 3): one owner (a user by id or a
 * scope by key) and optional filters, sort and page. Immutable; built with {@see self::forUser()} or
 * {@see self::forScope()} and the `with*()` methods.
 */
final readonly class VaultItemCriteria
{
    /** Largest page a query returns. */
    public const MAX_LIMIT = 500;

    /** Page size when none is given. */
    public const DEFAULT_LIMIT = 50;

    /**
     * @param string|null $ownerUserId the personal owner's id (exactly one of the user id and the scope is set)
     * @param string|null $ownerScope the owning scope
     * @param string|null $folderId only items in this folder
     * @param bool $withoutFolder only items in no folder (ignored when `folderId` is set)
     * @param string|null $tagId only items carrying this tag
     * @param string|null $title only items with exactly this title (compared byte for byte)
     * @param string|null $titleContains only items whose title contains this text (as the database compares it)
     *
     * @throws InvalidArgumentException on an invalid owner, page or filter
     */
    private function __construct(
        public ?string $ownerUserId,
        public ?string $ownerScope,
        public ?VaultItemType $type = null,
        public ?string $folderId = null,
        public bool $withoutFolder = false,
        public ?string $tagId = null,
        public ?string $title = null,
        public ?string $titleContains = null,
        public VaultTrashFilter $trash = VaultTrashFilter::Active,
        public VaultItemSort $sort = VaultItemSort::UpdatedDesc,
        public int $offset = 0,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        if ($offset < 0) {
            throw new InvalidArgumentException('The offset cannot be negative.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('The limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        if ($titleContains !== null && trim($titleContains) === '') {
            throw new InvalidArgumentException('The title filter cannot be blank.');
        }
    }

    /**
     * The personal items of a user, by id (the id as the vault stores it: never trimmed, at most 128 characters).
     *
     * @throws InvalidArgumentException when the id is blank or too long
     */
    public static function forUser(string $userId): self
    {
        if (trim($userId) === '' || mb_strlen($userId) > VaultMetadataListener::USER_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf('A vault user id is 1 to %d characters and not blank.', VaultMetadataListener::USER_ID_LENGTH));
        }

        return new self($userId, null);
    }

    /**
     * The items of a scope (the scope key of the host, e.g. an organisation).
     *
     * @throws InvalidArgumentException when the key is not a valid scope key
     */
    public static function forScope(string $scope): self
    {
        if (!VaultOwner::isValidScope($scope)) {
            throw new InvalidArgumentException('Invalid vault scope key.');
        }

        return new self(null, $scope);
    }

    /**
     * The items of an owner key as the change events carry it: `user:<id>` or `scope:<key>`.
     *
     * @throws InvalidArgumentException when the key is neither
     */
    public static function forOwnerKey(string $ownerKey): self
    {
        if (str_starts_with($ownerKey, 'user:')) {
            return self::forUser(substr($ownerKey, 5));
        }
        if (str_starts_with($ownerKey, 'scope:')) {
            return self::forScope(substr($ownerKey, 6));
        }

        throw new InvalidArgumentException('A vault owner key is "user:<id>" or "scope:<key>".');
    }

    /**
     * The owner key of the criteria (`user:<id>` or `scope:<key>`).
     */
    public function ownerKey(): string
    {
        return $this->ownerScope !== null ? 'scope:' . $this->ownerScope : 'user:' . $this->ownerUserId;
    }

    /**
     * Only items of this type (null: every type).
     */
    public function withType(?VaultItemType $type): self
    {
        return $this->copy(['type' => $type]);
    }

    /**
     * Only items in this folder (null: any folder).
     */
    public function inFolder(?string $folderId): self
    {
        return $this->copy(['folderId' => self::nonEmpty($folderId), 'withoutFolder' => false]);
    }

    /**
     * Only items in no folder.
     */
    public function withoutFolder(): self
    {
        return $this->copy(['folderId' => null, 'withoutFolder' => true]);
    }

    /**
     * Only items carrying this tag (null: any tag).
     */
    public function withTag(?string $tagId): self
    {
        return $this->copy(['tagId' => self::nonEmpty($tagId)]);
    }

    /**
     * Only items with exactly this title, compared byte for byte (null: any title).
     */
    public function withTitle(?string $title): self
    {
        return $this->copy(['title' => $title]);
    }

    /**
     * Only items whose title contains the text (null: any title).
     *
     * @throws InvalidArgumentException when the text is blank
     */
    public function withTitleContaining(?string $text): self
    {
        return $this->copy(['titleContains' => $text]);
    }

    /**
     * Active (default), trashed or every item.
     */
    public function withTrash(VaultTrashFilter $trash): self
    {
        return $this->copy(['trash' => $trash]);
    }

    /**
     * Order of the results.
     */
    public function sortedBy(VaultItemSort $sort): self
    {
        return $this->copy(['sort' => $sort]);
    }

    /**
     * The page to return.
     *
     * @throws InvalidArgumentException when the offset is negative or the limit out of range
     */
    public function page(int $offset, int $limit = self::DEFAULT_LIMIT): self
    {
        return $this->copy(['offset' => $offset, 'limit' => $limit]);
    }

    /**
     * A copy with some values replaced (a key present with null sets null).
     *
     * @param array{type?: VaultItemType|null, folderId?: string|null, withoutFolder?: bool, tagId?: string|null, title?: string|null, titleContains?: string|null, trash?: VaultTrashFilter, sort?: VaultItemSort, offset?: int, limit?: int} $changes
     */
    private function copy(array $changes): self
    {
        return new self(
            $this->ownerUserId,
            $this->ownerScope,
            array_key_exists('type', $changes) ? $changes['type'] : $this->type,
            array_key_exists('folderId', $changes) ? $changes['folderId'] : $this->folderId,
            $changes['withoutFolder'] ?? $this->withoutFolder,
            array_key_exists('tagId', $changes) ? $changes['tagId'] : $this->tagId,
            array_key_exists('title', $changes) ? $changes['title'] : $this->title,
            array_key_exists('titleContains', $changes) ? $changes['titleContains'] : $this->titleContains,
            $changes['trash'] ?? $this->trash,
            $changes['sort'] ?? $this->sort,
            $changes['offset'] ?? $this->offset,
            $changes['limit'] ?? $this->limit,
        );
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || strlen($value) === 0 ? null : $value;
    }
}

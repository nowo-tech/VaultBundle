<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use InvalidArgumentException;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Support\UserIdResolver;

use function mb_strlen;
use function preg_replace;
use function trim;

final readonly class VaultTagService
{
    public const MAX_NAME_LENGTH = 64;

    public function __construct(
        private VaultTagRepositoryInterface $tagRepository,
        private VaultItemRepositoryInterface $itemRepository,
    ) {
    }

    /**
     * @return list<VaultTag>
     */
    public function listForCreator(object $creator): array
    {
        return $this->tagRepository->findByCreator($creator);
    }

    /**
     * @param list<string> $tagNames
     */
    public function syncItemTags(VaultItem $item, object $creator, array $tagNames): void
    {
        $tags = [];
        $seen = [];
        foreach ($tagNames as $name) {
            $normalized = self::normalizeName($name);
            if ($normalized === '') {
                continue;
            }

            $tag = $this->findOrCreate($creator, $normalized);
            if (isset($seen[$tag->getId()])) {
                continue;
            }

            $seen[$tag->getId()] = true;
            $tags[]              = $tag;
        }

        $item->setTags($tags);
        $this->itemRepository->save($item);
    }

    public function findOrCreate(object $creator, string $name): VaultTag
    {
        $normalized = self::normalizeName($name);
        if ($normalized === '') {
            throw new InvalidArgumentException('Tag name cannot be empty.');
        }

        $existing = $this->tagRepository->findOneByCreatorAndName($creator, $normalized);
        if ($existing instanceof VaultTag) {
            return $existing;
        }

        $tag = new VaultTag($normalized, $creator);
        $this->tagRepository->save($tag);

        return $tag;
    }

    /**
     * Deletes a tag owned by the creator. Items keep their other tags; this label is removed from them.
     */
    public function deleteForCreator(object $creator, string $tagId): void
    {
        $tag = $this->tagRepository->findById($tagId);
        if (!$tag instanceof VaultTag || !UserIdResolver::isSameUser($creator, $tag->getCreator())) {
            throw new InvalidArgumentException('Tag not found or not owned by the current user.');
        }

        $this->tagRepository->remove($tag);
    }

    public static function normalizeName(string $name): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized) > self::MAX_NAME_LENGTH) {
            $normalized = mb_substr($normalized, 0, self::MAX_NAME_LENGTH);
        }

        return $normalized;
    }
}

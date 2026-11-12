<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultTagChangeEvent;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultScopeTagRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function mb_strlen;
use function preg_replace;
use function trim;

/**
 * Lists, creates, synchronises and deletes the creator's vault tags.
 */
final readonly class VaultTagService
{
    public const MAX_NAME_LENGTH = 64;

    /**
     * Creates the service with the tag and item repositories.
     */
    public function __construct(
        private VaultTagRepositoryInterface $tagRepository,
        private VaultItemRepositoryInterface $itemRepository,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Returns the creator's tags.
     *
     * @return list<VaultTag>
     */
    public function listForCreator(object $creator): array
    {
        return $this->tagRepository->findByCreator($creator);
    }

    /**
     * Returns the scope's shared tags (1.16.0).
     *
     * @return list<VaultTag>
     */
    public function listForScope(string $scope): array
    {
        return $this->scopeTags()->findByScope($scope);
    }

    /**
     * Replaces the item tags with the normalised, deduplicated names (creating missing tags). The tags have the
     * item's owner: the creator's tags for a personal item, the scope's tags for a scope item (1.16.0).
     *
     * @param list<string> $tagNames
     */
    public function syncItemTags(VaultItem $item, object $creator, array $tagNames): void
    {
        $scope = $item->getOwnerScope();
        $tags  = [];
        $seen  = [];
        foreach ($tagNames as $name) {
            $normalized = self::normalizeName($name);
            if ($normalized === '') {
                continue;
            }

            $tag = $scope !== null ? $this->findOrCreateInScope($scope, $normalized) : $this->findOrCreate($creator, $normalized);
            if (isset($seen[$tag->getId()])) {
                continue;
            }

            $seen[$tag->getId()] = true;
            $tags[]              = $tag;
        }

        $item->setTags($tags);
        $this->itemRepository->save($item);
    }

    /**
     * Returns the creator's tag with this name, creating it when missing.
     */
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
        $this->changed($tag, VaultTagChangeEvent::KIND_CREATED, $creator);

        return $tag;
    }

    /**
     * Returns the scope tag with this name, creating it when missing (1.16.0).
     */
    public function findOrCreateInScope(string $scope, string $name): VaultTag
    {
        $normalized = self::normalizeName($name);
        if ($normalized === '') {
            throw new InvalidArgumentException('Tag name cannot be empty.');
        }

        $existing = $this->scopeTags()->findOneByScopeAndName($scope, $normalized);
        if ($existing instanceof VaultTag) {
            return $existing;
        }

        $tag = VaultTag::forScope($normalized, $scope);
        $this->tagRepository->save($tag);
        $this->changed($tag, VaultTagChangeEvent::KIND_CREATED, null);

        return $tag;
    }

    /**
     * Deletes a tag owned by the creator. Items keep their other tags; this label is removed from them.
     */
    public function deleteForCreator(object $creator, string $tagId): void
    {
        $tag = $this->tagRepository->findById($tagId);
        if (!$tag instanceof VaultTag || !$tag->getOwner()->isUser($creator)) {
            throw new InvalidArgumentException('Tag not found or not owned by the current user.');
        }

        $tagId    = $tag->getId();
        $ownerKey = $tag->getOwner()->key();
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->tagRepository->remove($tag);
        $this->eventDispatcher?->dispatch(
            new VaultTagChangeEvent(UserIdResolver::getId($creator), $tagId, $ownerKey, VaultTagChangeEvent::KIND_DELETED, $this->now()),
            VaultEvents::TAG_CHANGED,
        );
    }

    /**
     * TAG_CHANGED after the vault's flush (1.19.0): ids only.
     */
    private function changed(VaultTag $tag, string $kind, ?object $actor): void
    {
        $this->eventDispatcher?->dispatch(
            new VaultTagChangeEvent($actor !== null ? UserIdResolver::getId($actor) : null, $tag->getId(), $tag->getOwner()->key(), $kind, $this->now()),
            VaultEvents::TAG_CHANGED,
        );
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function scopeTags(): VaultScopeTagRepositoryInterface
    {
        if (!$this->tagRepository instanceof VaultScopeTagRepositoryInterface) {
            throw new LogicException('Scope tags need a tag repository implementing VaultScopeTagRepositoryInterface.');
        }

        return $this->tagRepository;
    }

    /**
     * Collapses whitespace, trims and truncates a tag name to the maximum length.
     */
    public static function normalizeName(string $name): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized) > self::MAX_NAME_LENGTH) {
            return mb_substr($normalized, 0, self::MAX_NAME_LENGTH);
        }

        return $normalized;
    }
}

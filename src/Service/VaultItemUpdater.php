<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function in_array;
use function is_string;

/**
 * Updates vault items and re-encrypts their payload.
 *
 * Since 1.16.0 it records `secret_changed_at` only when a secret field changed (compared in memory with the stored
 * payload) and emits ITEM_CHANGED (`updated`, plus `moved` when the folder changed).
 */
final readonly class VaultItemUpdater
{
    /**
     * Creates the service with the item repository, the payload cryptographer and the optional 1.16.0 collaborators.
     */
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultPayloadCryptographerInterface $cryptographer,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Updates title, encrypted payload and folder of an item and persists it.
     *
     * @param array<string, mixed> $payload
     * @param object|null $actor the user making the change (ITEM_CHANGED actor id)
     */
    public function update(VaultItem $item, string $title, array $payload, ?VaultFolder $folder = null, ?object $actor = null): VaultItem
    {
        $secretChanged = $this->secretChanged($item, $payload);
        $moved         = $item->getFolder()?->getId() !== $folder?->getId();
        $now           = $this->clock?->now() ?? new DateTimeImmutable();

        $item->setTitle($title);
        $item->setCiphertext($this->cryptographer->encrypt($payload));
        $item->setFolder($folder);
        if ($secretChanged) {
            $item->markSecretChanged($now);
        }
        $this->itemRepository->save($item);

        $actorId = $actor !== null ? UserIdResolver::getId($actor) : null;
        $this->dispatch($actorId, $item, VaultItemChangeEvent::KIND_UPDATED, $secretChanged, $now);
        if ($moved) {
            $this->dispatch($actorId, $item, VaultItemChangeEvent::KIND_MOVED, false, $now);
        }

        return $item;
    }

    /**
     * Payload keys whose change counts as a secret change for the type (the secret fields, plus the attachment).
     *
     * @return list<string>
     */
    public static function secretKeys(VaultItemType $type): array
    {
        $keys = VaultItemFormData::secretPayloadFields($type);
        if (in_array($type, VaultItemType::documentTypes(), true)) {
            $keys[] = 'attachmentContent';
        }

        return $keys;
    }

    /**
     * Whether a secret field differs between the stored payload and the new one (both already in this process).
     *
     * @param array<string, mixed> $payload
     */
    private function secretChanged(VaultItem $item, array $payload): bool
    {
        try {
            $stored = $this->cryptographer->decrypt($item->getCiphertext());
        } catch (Throwable) {
            return true;
        }

        foreach (self::secretKeys($item->getItemType()) as $key) {
            if ($this->text($stored[$key] ?? null) !== $this->text($payload[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function dispatch(?string $actorId, VaultItem $item, string $kind, bool $secretChanged, DateTimeImmutable $now): void
    {
        $this->eventDispatcher?->dispatch(
            new VaultItemChangeEvent($actorId, $item->getId(), $item->getOwner()->key(), $kind, $secretChanged, $now),
            VaultEvents::ITEM_CHANGED,
        );
    }
}

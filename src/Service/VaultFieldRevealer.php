<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFieldRevealCheckEvent;
use Nowo\VaultBundle\Event\VaultItemRevealedEvent;
use Nowo\VaultBundle\Event\VaultRevealThrottledEvent;
use Nowo\VaultBundle\Exception\VaultDecryptionFailedException;
use Nowo\VaultBundle\Exception\VaultRevealRefusedException;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultRevealRateLimiter;
use Nowo\VaultBundle\Security\VaultSecretAccessRateLimiter;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultRevealedValue;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_filter;
use function array_values;
use function base64_decode;
use function in_array;
use function is_array;
use function is_string;

/**
 * Lets one field (or the attachment) of an item leave the vault, after the caller checked View access (1.16.0,
 * contract §6.3): the field must be revealable for the item type, ITEM_FIELD_REVEAL_CHECK may veto it, the per-user
 * limits apply (`security.secret_access_rate_limit`, `reveal.rate_limit`), the payload is decrypted, and the audit event
 * (ITEM_REVEALED, ITEM_COPIED or ITEM_ATTACHMENT_DOWNLOADED) is dispatched **before** the value is returned: a
 * listener that throws refuses the reveal (no audit, no reveal). Nothing it logs, dispatches or throws holds a value.
 */
final readonly class VaultFieldRevealer
{
    /**
     * @param array<string, list<string>>|null $revealableFields `reveal.revealable_fields` (item type => payload keys); null = the secret fields of each type
     */
    public function __construct(
        private VaultPayloadCryptographerInterface $cryptographer,
        private EventDispatcherInterface $eventDispatcher,
        private ?VaultRevealRateLimiter $rateLimiter = null,
        private ?VaultSecretAccessRateLimiter $secretAccessRateLimiter = null,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
        private ?array $revealableFields = null,
    ) {
    }

    /**
     * Payload keys of the item type that the reveal route answers (the type's secret fields unless configured).
     *
     * @return list<string>
     */
    public function revealableFields(VaultItemType $type): array
    {
        return $this->revealableFields[$type->value] ?? VaultItemFormData::secretPayloadFields($type);
    }

    /**
     * One field for a Show (`reveal`) or a Copy (`copy`). A field allowed for the type but empty returns `''`.
     *
     * @throws VaultRevealRefusedException
     * @throws VaultDecryptionFailedException
     */
    public function reveal(UserInterface $user, VaultItem $item, string $field, string $purpose = VaultItemRevealedEvent::PURPOSE_REVEAL): VaultRevealedValue
    {
        $purpose = $purpose === VaultItemRevealedEvent::PURPOSE_COPY ? VaultItemRevealedEvent::PURPOSE_COPY : VaultItemRevealedEvent::PURPOSE_REVEAL;
        if (!in_array($field, $this->revealableFields($item->getItemType()), true)) {
            throw new VaultRevealRefusedException(VaultRevealRefusedException::NOT_REVEALABLE);
        }

        $this->authorise($user, $item, $field, $purpose);
        $payload = $this->decrypt($item);
        $raw     = $payload[$field] ?? '';
        $value   = is_array($raw)
            ? array_values(array_filter($raw, is_string(...)))
            : (is_string($raw) ? $raw : '');

        $this->audit($user, $item, $field, $purpose, $purpose === VaultItemRevealedEvent::PURPOSE_COPY ? VaultEvents::ITEM_COPIED : VaultEvents::ITEM_REVEALED);

        return new VaultRevealedValue($value);
    }

    /**
     * The attachment of a document item: its stored file name and its bytes.
     *
     * @throws VaultRevealRefusedException
     * @throws VaultDecryptionFailedException
     *
     * @return array{name: string, content: VaultRevealedValue}
     */
    public function attachment(UserInterface $user, VaultItem $item): array
    {
        if (!in_array($item->getItemType(), VaultItemType::documentTypes(), true)) {
            throw new VaultRevealRefusedException(VaultRevealRefusedException::NOT_REVEALABLE);
        }

        $this->authorise($user, $item, 'attachment', VaultItemRevealedEvent::PURPOSE_ATTACHMENT);
        $payload = $this->decrypt($item);
        $encoded = $payload['attachmentContent'] ?? null;
        $content = is_string($encoded) && $encoded !== '' ? base64_decode($encoded, true) : false;
        if ($content === false || $content === '') {
            throw new VaultRevealRefusedException(VaultRevealRefusedException::NOT_REVEALABLE);
        }

        $this->audit($user, $item, 'attachment', VaultItemRevealedEvent::PURPOSE_ATTACHMENT, VaultEvents::ITEM_ATTACHMENT_DOWNLOADED);
        $name = $payload['attachmentName'] ?? null;

        return ['name' => is_string($name) ? $name : '', 'content' => new VaultRevealedValue($content)];
    }

    /**
     * Field veto, then the rate limits (both refuse with an exception; throttling dispatches REVEAL_THROTTLED).
     */
    private function authorise(UserInterface $user, VaultItem $item, string $field, string $purpose): void
    {
        $check = new VaultFieldRevealCheckEvent($user, $item, $field, $purpose, VaultItemRevealedEvent::CHANNEL_WEB);
        $this->eventDispatcher->dispatch($check, VaultEvents::ITEM_FIELD_REVEAL_CHECK);
        if (!$check->isGranted()) {
            throw new VaultRevealRefusedException(VaultRevealRefusedException::NOT_REVEALABLE);
        }

        if ($this->secretAccessRateLimiter instanceof VaultSecretAccessRateLimiter && !$this->secretAccessRateLimiter->consume($user)) {
            $this->throttled($user, $item, VaultRevealThrottledEvent::WINDOW_SECRET_ACCESS, $this->secretAccessRateLimiter->getWindowSeconds());
        }

        $limit = $this->rateLimiter?->consume($user, $item->getId(), $field);
        if ($limit !== null) {
            $this->throttled($user, $item, $limit['window'], $limit['retryAfter']);
        }
    }

    private function throttled(UserInterface $user, VaultItem $item, string $window, int $retryAfter): never
    {
        $userId = UserIdResolver::getId($user);
        $this->logger()->warning('Vault reveal throttled.', [
            'bundle'  => 'nowo_vault',
            'action'  => 'reveal_throttled',
            'user_id' => $userId,
            'item_id' => $item->getId(),
            'window'  => $window,
        ]);
        $this->eventDispatcher->dispatch(new VaultRevealThrottledEvent($userId, $item->getId(), $window), VaultEvents::REVEAL_THROTTLED);

        throw new VaultRevealRefusedException(VaultRevealRefusedException::THROTTLED, $window, $retryAfter);
    }

    /**
     * @return array<string, mixed>
     */
    private function decrypt(VaultItem $item): array
    {
        try {
            return $this->cryptographer->decrypt($item->getCiphertext());
        } catch (Throwable) {
            // Generic and without the previous exception: nothing about the key or the payload leaves this method.
            throw new VaultDecryptionFailedException();
        }
    }

    /**
     * Dispatches the audit event before the value leaves: a listener that throws (e.g. its audit write failed)
     * refuses the reveal.
     */
    private function audit(UserInterface $user, VaultItem $item, string $field, string $purpose, string $eventName): void
    {
        $userId = UserIdResolver::getId($user);

        try {
            $this->eventDispatcher->dispatch(new VaultItemRevealedEvent(
                $userId,
                $item->getId(),
                $item->getItemType(),
                [$field],
                VaultItemRevealedEvent::CHANNEL_WEB,
                $purpose,
                $item->getOwner()->key(),
                $this->clock?->now() ?? new DateTimeImmutable(),
            ), $eventName);
        } catch (Throwable $exception) {
            $this->logger()->error('Vault reveal refused: audit listener failed.', [
                'bundle'    => 'nowo_vault',
                'action'    => 'reveal_audit_failed',
                'user_id'   => $userId,
                'item_id'   => $item->getId(),
                'exception' => $exception::class,
            ]);

            throw new VaultRevealRefusedException(VaultRevealRefusedException::AUDIT_FAILED);
        }

        $this->logger()->info('Vault field revealed.', [
            'bundle'  => 'nowo_vault',
            'action'  => 'item_' . $purpose,
            'user_id' => $userId,
            'item_id' => $item->getId(),
            'field'   => $field,
            'purpose' => $purpose,
            'channel' => VaultItemRevealedEvent::CHANNEL_WEB,
        ]);
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}

<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * REVEAL_THROTTLED (1.16.0): a reveal, copy or attachment download was refused by `reveal.rate_limit` (ids only), so
 * the host can alert on scraping.
 */
final class VaultRevealThrottledEvent extends Event
{
    public const WINDOW_MINUTE        = 'minute';
    public const WINDOW_HOUR          = 'hour';
    public const WINDOW_FIELD         = 'item_field_minute';
    public const WINDOW_SECRET_ACCESS = 'secret_access';

    /**
     * Creates the event.
     */
    public function __construct(
        private readonly ?string $actorId,
        private readonly string $itemId,
        private readonly string $window,
    ) {
    }

    /**
     * User id of the throttled user.
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Item id of the refused request.
     */
    public function getItemId(): string
    {
        return $this->itemId;
    }

    /**
     * The exhausted window (WINDOW_*).
     */
    public function getWindow(): string
    {
        return $this->window;
    }
}

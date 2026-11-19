<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\ValueObject\VaultOwner;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Contracts\EventDispatcher\Event;

use function array_diff;
use function array_intersect;
use function array_unique;
use function array_values;
use function in_array;

/**
 * SEARCH_SCOPES (1.21.0): which scope vaults the SearchKit group `vault_items` searches for one viewer, dispatched once
 * per search before the SQL query. By default every scope is searched, and each scope item found is kept only when the
 * scope access resolver says the viewer is a member (the same rule as the scope pages). A listener can narrow that in
 * SQL: limitTo() the scopes the viewer belongs to (so other scopes never fill the candidate window), exclude() scopes
 * whose vault is off for this page, or excludeAll(). Narrowing only: no listener can add a scope the resolver refuses.
 *
 * A malformed scope key is ignored. Personal items and items shared with the viewer from a personal vault are not
 * affected. A new event per search: nothing is kept between requests.
 */
final class VaultSearchScopesEvent extends Event
{
    /** @var list<string>|null null = every scope */
    private ?array $only = null;

    /** @var list<string> */
    private array $excluded = [];

    /**
     * Creates the event for the viewer.
     */
    public function __construct(
        private readonly VaultUserRef $user,
    ) {
    }

    /**
     * The viewer, as a framework-free reference.
     */
    public function getUserRef(): VaultUserRef
    {
        return $this->user;
    }

    /**
     * The viewer's vault user id, or null when it has none.
     */
    public function getUserId(): ?string
    {
        return $this->user->id;
    }

    /**
     * Searches only these scopes (intersected with what earlier listeners allowed).
     *
     * @param list<string> $scopes scope keys
     */
    public function limitTo(array $scopes): void
    {
        $valid = self::valid($scopes);
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->only = $this->only === null ? $valid : array_values(array_intersect($this->only, $valid));
    }

    /**
     * Never searches these scopes.
     *
     * @param list<string> $scopes scope keys
     */
    public function exclude(array $scopes): void
    {
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->excluded = self::valid([...$this->excluded, ...$scopes]);
    }

    /**
     * Searches no scope vault at all (personal and personally shared items only).
     */
    public function excludeAll(): void
    {
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->only = [];
    }

    /**
     * Whether every scope is still searched (no listener narrowed it).
     */
    public function searchesEveryScope(): bool
    {
        return $this->only === null;
    }

    /**
     * The scopes searched when narrowed with limitTo() / excludeAll() (excluded ones removed), or null for every scope.
     *
     * @return list<string>|null
     */
    public function getScopes(): ?array
    {
        return $this->only === null ? null : array_values(array_diff($this->only, $this->excluded));
    }

    /**
     * The excluded scopes.
     *
     * @return list<string>
     */
    public function getExcluded(): array
    {
        return $this->excluded;
    }

    /**
     * Whether the scope is searched.
     */
    public function isSearched(string $scope): bool
    {
        if (!VaultOwner::isValidScope($scope) || in_array($scope, $this->excluded, true)) {
            return false;
        }

        return $this->only === null || in_array($scope, $this->only, true);
    }

    /**
     * @param list<string> $scopes
     *
     * @return list<string>
     */
    private static function valid(array $scopes): array
    {
        $valid = [];
        foreach ($scopes as $scope) {
            if (VaultOwner::isValidScope($scope)) {
                $valid[] = $scope;
            }
        }

        return array_values(array_unique($valid));
    }
}

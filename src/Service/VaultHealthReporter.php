<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use LogicException;
use Nowo\VaultBundle\Dto\VaultHealthItemResult;
use Nowo\VaultBundle\Dto\VaultHealthReport;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultHealthAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultHealthReportGeneratedEvent;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultHealthThrottledException;
use Nowo\VaultBundle\Exception\VaultHealthTooLargeException;
use Nowo\VaultBundle\Integration\PasswordStrengthIntegration;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultScopeItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultBreachCheckerInterface;
use Nowo\VaultBundle\Security\VaultPasswordPolicyResolverInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_map;
use function array_values;
use function class_exists;
use function count;
use function hash;
use function hash_hmac;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use function is_object;
use function is_string;
use function max;
use function mb_strlen;
use function method_exists;
use function preg_match;
use function random_bytes;
use function sodium_memzero;

/**
 * Server-side health report (1.16.0, contract §7): runs in the request's PHP process, decrypts in memory only, writes
 * and sends nothing else.
 *
 * - Items: the owner's active login items the viewer may View; the others are counted in `skipped` and never compared.
 * - Reuse: a per-run random 32-byte HMAC key; fingerprints stay in a local array and the key is wiped. Reuse never
 *   crosses owners.
 * - Weak: the nowo-tech/password-strength-bundle evaluator when installed (its missing rules) plus the policy's
 *   character-class count, else a built-in length / classes check; an empty password is always weak.
 * - Old: `secret_changed_at` (falling back to `created_at`) older than the policy's `maxAgeDays`.
 * - Breached: the optional host {@see VaultBreachCheckerInterface}; null when absent or failing.
 * - Cache: off unless `health.cache_ttl` > 0; then only the report (flags, titles, group numbers) is cached, per viewer.
 */
final readonly class VaultHealthReporter implements VaultHealthReportProviderInterface, VaultHealthReporterInterface
{
    /**
     * Stores the collaborators and the limits.
     *
     * @param object|null $strengthEvaluator `Nowo\PasswordStrengthBundle\Service\PasswordStrengthEvaluator` when installed
     */
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultAccessGuard $accessGuard,
        private VaultPayloadCryptographerInterface $cryptographer,
        private EventDispatcherInterface $eventDispatcher,
        private VaultPasswordPolicyResolverInterface $policyResolver,
        private ?VaultBreachCheckerInterface $breachChecker = null,
        private ?object $strengthEvaluator = null,
        private ?CacheItemPoolInterface $cache = null,
        private int $cacheTtl = 0,
        private int $maxItems = 2000,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
        /** health.min_interval_seconds: one computed report per viewer and owner per window (0 = none; SEC-004 FQ-I2). */
        private int $minIntervalSeconds = 0,
        /** health.breach_cache_ttl: seconds a breach answer is kept per item and secret change (0 = never). */
        private int $breachCacheTtl = 0,
        /** 1.19.0 (NJ-1): resolves a reference given by id through the user locator port. */
        private ?VaultUserRefResolver $refs = null,
    ) {
    }

    /**
     * Report over the scope's login items the viewer may View.
     */
    public function forScope(UserInterface $viewer, string $scope, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        $owner  = VaultOwner::scope($scope);
        $access = $this->accessGuard->resolveScopeAccess($viewer, $scope);
        if (!$access instanceof VaultScopeAccess) {
            throw new VaultAccessDeniedException();
        }

        $check = new VaultHealthAccessCheckEvent($viewer, $owner->key(), $access->health);
        $this->eventDispatcher->dispatch($check, VaultEvents::HEALTH_ACCESS_CHECK);
        if (!$check->isGranted()) {
            throw new VaultAccessDeniedException();
        }

        $repository = $this->itemRepository;
        if (!$repository instanceof VaultScopeItemRepositoryInterface) {
            throw new LogicException('Scope health reports need an item repository implementing VaultScopeItemRepositoryInterface.');
        }

        $items = $repository->findActiveByScope($scope, VaultItemType::Login);
        $this->assertSize($items);
        $permissions = $this->accessGuard->resolvePermissionMap($viewer, $items);
        $visible     = array_values(array_filter($items, static fn (VaultItem $item): bool => ($permissions[$item->getId()] ?? null) !== null));

        return $this->report($viewer, $owner, $visible, count($items) - count($visible), $policy);
    }

    /**
     * Report over the owner's personal login items.
     */
    public function reportForScope(VaultUserRef $viewer, string $scope, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        return $this->forScope($this->userOf($viewer), $scope, $policy);
    }

    public function reportForPersonalVault(VaultUserRef $owner, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        return $this->forPersonalVault($this->userOf($owner), $policy);
    }

    /**
     * The user object of the reference, or the user its id resolves to through the user locator port (audit NJ-1).
     *
     * @throws VaultAccessDeniedException when neither gives a user
     */
    private function userOf(VaultUserRef $ref): UserInterface
    {
        return ($this->refs instanceof VaultUserRefResolver ? $this->refs->resolve($ref) : VaultUserRefs::toUser($ref)) ?? throw new VaultAccessDeniedException();
    }

    public function forPersonalVault(UserInterface $owner, ?VaultPasswordPolicy $policy = null): VaultHealthReport
    {
        $items = $this->itemRepository->findByCreatorAndItemType($owner, VaultItemType::Login);
        $this->assertSize($items);

        return $this->report($owner, VaultOwner::user($owner), $items, 0, $policy);
    }

    /**
     * @param list<VaultItem> $items
     */
    private function assertSize(array $items): void
    {
        if (count($items) > $this->maxItems) {
            throw new VaultHealthTooLargeException(count($items), $this->maxItems);
        }
    }

    /**
     * Cached report (when enabled), else computed; HEALTH_REPORT_GENERATED either way.
     *
     * @param list<VaultItem> $items items the viewer may View
     */
    private function report(UserInterface $viewer, VaultOwner $owner, array $items, int $skipped, ?VaultPasswordPolicy $policy): VaultHealthReport
    {
        $policy ??= $this->policyResolver->forOwner($owner) ?? new VaultPasswordPolicy();
        $cache = $this->cacheTtl > 0 ? $this->cache : null;
        if ($cache instanceof CacheItemPoolInterface) {
            $cacheItem = $cache->getItem($this->cacheKey($viewer, $owner, $items, $skipped, $policy));
            $cached    = $cacheItem->isHit() ? $cacheItem->get() : null;
            $report    = $cached instanceof VaultHealthReport ? $cached : $this->compute($viewer, $owner, $items, $skipped, $policy);
            if (!$cached instanceof VaultHealthReport) {
                $cacheItem->set($report);
                $cacheItem->expiresAfter($this->cacheTtl);
                $cache->save($cacheItem);
            }
        } else {
            $report = $this->compute($viewer, $owner, $items, $skipped, $policy);
        }

        $actorId = UserIdResolver::getId($viewer);
        $this->eventDispatcher->dispatch(new VaultHealthReportGeneratedEvent(
            $actorId,
            $owner->key(),
            $report->examined,
            $report->weakCount(),
            $report->reusedCount(),
            $report->oldCount(),
            $report->breachedCount(),
        ), VaultEvents::HEALTH_REPORT_GENERATED);
        ($this->logger ?? new NullLogger())->info('Vault health report generated.', [
            'bundle'   => 'nowo_vault',
            'action'   => 'health_report',
            'user_id'  => $actorId,
            'owner'    => $owner->key(),
            'examined' => $report->examined,
            'weak'     => $report->weakCount(),
            'reused'   => $report->reusedCount(),
            'old'      => $report->oldCount(),
            'breached' => $report->breachedCount(),
        ]);

        return $report;
    }

    /**
     * @param list<VaultItem> $items
     */
    private function compute(UserInterface $viewer, VaultOwner $owner, array $items, int $skipped, VaultPasswordPolicy $policy): VaultHealthReport
    {
        $this->throttle($viewer, $owner);
        $now          = $this->clock?->now() ?? new DateTimeImmutable();
        $key          = random_bytes(32);
        $fingerprints = [];
        $passwords    = [];
        $weak         = [];
        $breached     = [];
        foreach ($items as $item) {
            try {
                $payload = $this->cryptographer->decrypt($item->getCiphertext());
            } catch (Throwable) {
                // Undecryptable (e.g. during a key rotation): left out, never reported as anything.
                ++$skipped;

                continue;
            }

            $password                     = is_string($payload['password'] ?? null) ? $payload['password'] : '';
            $passwords[$item->getId()]    = $item;
            $fingerprints[$item->getId()] = $password === '' ? null : hash_hmac('sha256', $password, $key);
            $weak[$item->getId()]         = $this->weakReasons($password, $policy);
            $breached[$item->getId()]     = $password === '' ? null : $this->breached($item, $password);
            // Best effort: wipe the plaintext copies held by this method.
            sodium_memzero($password);
            if (is_string($payload['password'] ?? null)) {
                sodium_memzero($payload['password']);
            }
        }
        sodium_memzero($key);

        // Reuse groups: numbers local to this report, in the order of the first item of each group.
        $members = [];
        foreach ($fingerprints as $itemId => $fingerprint) {
            if ($fingerprint !== null) {
                $members[$fingerprint][] = (string) $itemId;
            }
        }
        $groups = [];
        $next   = 1;
        foreach ($members as $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $groups[$id] = $next;
                }
                ++$next;
            }
        }
        unset($fingerprints, $members);

        $results = [];
        foreach ($passwords as $itemId => $item) {
            $changed   = $item->getSecretChangedAt() ?? $item->getCreatedAt();
            $ageDays   = max(0, intdiv($now->getTimestamp() - $changed->getTimestamp(), 86400));
            $reasons   = $weak[$itemId] ?? [];
            $results[] = new VaultHealthItemResult(
                (string) $itemId,
                $item->getTitle(),
                $reasons !== [],
                $reasons,
                $groups[$itemId] ?? null,
                $policy->maxAgeDays !== null && $ageDays > $policy->maxAgeDays,
                $ageDays,
                $breached[$itemId] ?? null,
            );
        }

        return new VaultHealthReport($owner, $now, count($results), $skipped, $results);
    }

    /**
     * Missing rules of the password (empty list = not weak).
     *
     * @return list<string>
     */
    private function weakReasons(#[SensitiveParameter] string $password, VaultPasswordPolicy $policy): array
    {
        if ($password === '') {
            return ['empty'];
        }

        $reasons = [];
        if ($this->strengthEvaluator !== null && class_exists(PasswordStrengthIntegration::CONDITIONS_MODEL) && method_exists($this->strengthEvaluator, 'evaluate')) {
            $conditionsClass = PasswordStrengthIntegration::CONDITIONS_MODEL;
            $result          = $this->strengthEvaluator->evaluate($password, new $conditionsClass(minLength: $policy->minLength));
            $missing         = is_object($result) && is_array($result->missing ?? null) ? $result->missing : [];
            $reasons         = array_values(array_map(strval(...), $missing));
        } elseif (mb_strlen($password) < $policy->minLength) {
            $reasons[] = 'min_length';
        }

        $classes = 0;
        foreach (['/\p{Ll}/u', '/\p{Lu}/u', '/\d/', '/[^\p{L}\d]/u'] as $pattern) {
            $classes += preg_match($pattern, $password) === 1 ? 1 : 0;
        }
        if ($classes < $policy->minClasses && !in_array('min_classes', $reasons, true)) {
            $reasons[] = 'min_classes';
        }

        return $reasons;
    }

    /**
     * Breach answer of the host checker, cached per item id and secret change (never keyed by anything derived from the
     * value) when `health.breach_cache_ttl` > 0; failures (null) are not cached.
     */
    private function breached(VaultItem $item, #[SensitiveParameter] string $password): ?bool
    {
        if (!$this->breachChecker instanceof VaultBreachCheckerInterface) {
            return null;
        }

        $cache     = $this->breachCacheTtl > 0 ? $this->cache : null;
        $cacheItem = null;
        if ($cache instanceof CacheItemPoolInterface) {
            $changed   = ($item->getSecretChangedAt() ?? $item->getCreatedAt())->getTimestamp();
            $cacheItem = $cache->getItem('nowo_vault_breach_' . hash('sha256', $item->getId() . '|' . $changed));
            if ($cacheItem->isHit() && is_bool($cacheItem->get())) {
                return $cacheItem->get();
            }
        }

        try {
            $breached = $this->breachChecker->isBreached($password);
        } catch (Throwable) {
            return null;
        }

        if ($cache instanceof CacheItemPoolInterface && $breached !== null) {
            $cacheItem->set($breached);
            $cacheItem->expiresAfter($this->breachCacheTtl);
            $cache->save($cacheItem);
        }

        return $breached;
    }

    /**
     * One computed report per viewer and owner per `health.min_interval_seconds` (cached reports are not counted).
     *
     * @throws VaultHealthThrottledException
     */
    private function throttle(UserInterface $viewer, VaultOwner $owner): void
    {
        if (!$this->cache instanceof CacheItemPoolInterface || $this->minIntervalSeconds <= 0) {
            return;
        }

        $now   = ($this->clock?->now() ?? new DateTimeImmutable())->getTimestamp();
        $slot  = $this->cache->getItem('nowo_vault_health_throttle_' . hash('sha256', (UserIdResolver::getId($viewer) ?? $viewer->getUserIdentifier()) . '|' . $owner->key()));
        $until = $slot->isHit() ? $slot->get() : null;
        if (is_int($until) && $until > $now) {
            throw new VaultHealthThrottledException($until - $now);
        }

        $slot->set($now + $this->minIntervalSeconds);
        $slot->expiresAfter($this->minIntervalSeconds);
        $this->cache->save($slot);
    }

    /**
     * sha256(owner key ‖ viewer id ‖ max(updated_at) ‖ count ‖ policy hash): per viewer, and stale as soon as an item
     * changes. Contains no value.
     *
     * @param list<VaultItem> $items
     */
    private function cacheKey(UserInterface $viewer, VaultOwner $owner, array $items, int $skipped, VaultPasswordPolicy $policy): string
    {
        $latest = 0;
        foreach ($items as $item) {
            $latest = max($latest, $item->getUpdatedAt()->getTimestamp(), ($item->getSecretChangedAt() ?? $item->getCreatedAt())->getTimestamp());
        }

        return 'nowo_vault_health_' . hash('sha256', implode("\0", [
            $owner->key(),
            UserIdResolver::getId($viewer) ?? $viewer->getUserIdentifier(),
            (string) $latest,
            (string) count($items),
            (string) $skipped,
            $policy->hash(),
            implode(',', array_map(static fn (VaultItem $i): string => $i->getId(), $items)),
        ]));
    }
}

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
use Nowo\VaultBundle\Exception\VaultHealthTooLargeException;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultScopeItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultBreachCheckerInterface;
use Nowo\VaultBundle\Security\VaultPasswordPolicyResolverInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_keys;
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
final readonly class VaultHealthReporter implements VaultHealthReporterInterface
{
    /** Class of the optional strength bundle's conditions model. */
    private const CONDITIONS_CLASS = 'Nowo\\PasswordStrengthBundle\\Model\\PasswordConditions';

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
        $cacheItem = null;
        $report    = null;
        if ($this->cache instanceof CacheItemPoolInterface && $this->cacheTtl > 0) {
            $cacheItem = $this->cache->getItem($this->cacheKey($viewer, $owner, $items, $skipped, $policy));
            $cached    = $cacheItem->isHit() ? $cacheItem->get() : null;
            $report    = $cached instanceof VaultHealthReport ? $cached : null;
        }

        if (!$report instanceof VaultHealthReport) {
            $report = $this->compute($owner, $items, $skipped, $policy);
            if ($cacheItem !== null) {
                $cacheItem->set($report);
                $cacheItem->expiresAfter($this->cacheTtl);
                $this->cache?->save($cacheItem);
            }
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
    private function compute(VaultOwner $owner, array $items, int $skipped, VaultPasswordPolicy $policy): VaultHealthReport
    {
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

            $password                    = is_string($payload['password'] ?? null) ? $payload['password'] : '';
            $passwords[$item->getId()]   = $item;
            $fingerprints[$item->getId()] = $password === '' ? null : hash_hmac('sha256', $password, $key);
            $weak[$item->getId()]         = $this->weakReasons($password, $policy);
            $breached[$item->getId()]     = $password === '' ? null : $this->breached($password);
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
        if ($this->strengthEvaluator !== null && class_exists(self::CONDITIONS_CLASS) && method_exists($this->strengthEvaluator, 'evaluate')) {
            $conditionsClass = self::CONDITIONS_CLASS;
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

    private function breached(#[SensitiveParameter] string $password): ?bool
    {
        if (!$this->breachChecker instanceof VaultBreachCheckerInterface) {
            return null;
        }

        try {
            return $this->breachChecker->isBreached($password);
        } catch (Throwable) {
            return null;
        }
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

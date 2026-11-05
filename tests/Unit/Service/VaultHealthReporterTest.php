<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Closure;
use LogicException;
use Nowo\VaultBundle\Dto\VaultHealthItemResult;
use Nowo\VaultBundle\Dto\VaultHealthReport;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultHealthAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultHealthReportGeneratedEvent;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultHealthTooLargeException;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultScopeItemRepositoryInterface;
use Nowo\VaultBundle\Security\ConfigVaultPasswordPolicyResolver;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\VaultBreachCheckerInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultHealthReporter;
use Nowo\VaultBundle\Tests\Stub\InMemoryGrantRepository;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

use function bin2hex;
use function random_bytes;
use function serialize;

/**
 * Health report (1.16.0 §7): weak (both strategies), reuse groups, old via secret_changed_at, empty passwords, the
 * size limit, the reuse oracle, breach checks, caching, access checks and value-free outputs.
 */
final class VaultHealthReporterTest extends TestCase
{
    private const SCOPE = 'org-1';

    private MockClock $clock;
    private InMemoryGrantRepository $grants;
    private EventDispatcher $dispatcher;
    private RecordingLogger $logger;
    private string $marker;

    /** @var array<string, array<string, mixed>> item id => payload */
    private array $payloads = [];

    /** @var list<VaultItem> */
    private array $items = [];

    /** @var array<string, array<string, VaultScopeAccess>> */
    private array $members = [];

    protected function setUp(): void
    {
        $this->clock      = new MockClock('2026-11-05 12:00:00');
        $this->grants     = new InMemoryGrantRepository($this->clock->now());
        $this->dispatcher = new EventDispatcher();
        $this->logger     = new RecordingLogger();
        $this->marker     = 'MARK' . bin2hex(random_bytes(16)) . 'Aa1!';
        $this->members    = ['1' => [self::SCOPE => new VaultScopeAccess(VaultPermission::Admin, health: true)], '2' => [self::SCOPE => new VaultScopeAccess(null, health: true)]];
    }

    public function testWeakReuseOldAndEmpty(): void
    {
        $strong  = $this->item('Strong', $this->marker);
        $reuse   = $this->item('Reuse', $this->marker);
        $short   = $this->item('Short', 'aA1!');
        $classes = $this->item('Classes', 'alllowercaseletters');
        $empty   = $this->item('Empty', '');
        $old     = $this->item('Old', 'Another-unique-42', '-400 days');
        // updated_at is recent (a title change) but the secret is old: "old" follows secret_changed_at.
        $old->setTitle('Old renamed');
        $unique = $this->item('Unique', 'Different-unique-7');

        $events = [];
        $this->dispatcher->addListener(VaultEvents::HEALTH_REPORT_GENERATED, static function (VaultHealthReportGeneratedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $report = $this->reporter()->forScope(new TestUser('1'), self::SCOPE);

        self::assertSame(7, $report->examined);
        self::assertSame(0, $report->skipped);
        $byTitle = $this->byTitle($report->items);
        self::assertSame(1, $byTitle['Strong']->reuseGroup);
        self::assertSame(1, $byTitle['Reuse']->reuseGroup);
        self::assertNull($byTitle['Unique']->reuseGroup);
        self::assertFalse($byTitle['Strong']->weak);
        self::assertSame(['min_length'], $byTitle['Short']->weakReasons);
        self::assertSame(['min_classes'], $byTitle['Classes']->weakReasons);
        self::assertSame(['empty'], $byTitle['Empty']->weakReasons);
        self::assertNull($byTitle['Empty']->reuseGroup);
        self::assertTrue($byTitle['Old renamed']->old);
        self::assertSame(400, $byTitle['Old renamed']->ageDays);
        self::assertFalse($byTitle['Unique']->old);
        self::assertNull($byTitle['Unique']->breached);
        self::assertSame([$strong->getId(), $reuse->getId(), $short->getId(), $classes->getId(), $empty->getId(), $old->getId(), $unique->getId()], array_map(static fn (VaultHealthItemResult $r): string => $r->itemId, $report->items));
        self::assertSame(3, $report->weakCount());
        self::assertSame(2, $report->reusedCount());
        self::assertSame(1, $report->oldCount());
        self::assertSame(0, $report->breachedCount());
        self::assertEquals($this->clock->now(), $report->generatedAt);
        self::assertSame('scope:' . self::SCOPE, $report->owner->key());

        self::assertCount(1, $events);
        self::assertSame([7, 3, 2, 1, 0, '1', 'scope:org-1'], [$events[0]->getExamined(), $events[0]->getWeak(), $events[0]->getReused(), $events[0]->getOld(), $events[0]->getBreached(), $events[0]->getActorId(), $events[0]->getOwnerKey()]);
        self::assertStringNotContainsString($this->marker, serialize($report));
        self::assertStringNotContainsString($this->marker, serialize($events));
        self::assertStringNotContainsString($this->marker, serialize($this->logger->records));
    }

    public function testInvisibleItemsAreNeitherListedNorCompared(): void
    {
        // Oracle test: the viewer plants the marker in an item they can see; an item they cannot see holds the same.
        $mine   = $this->item('Planted', $this->marker);
        $hidden = $this->item('Invisible', $this->marker);
        $this->grants->save(new VaultGrant(VaultResourceType::Item, $mine->getId(), GranteeType::User, '2', VaultPermission::Read, null));

        $report = $this->reporter()->forScope(new TestUser('2'), self::SCOPE);

        self::assertSame(1, $report->examined);
        self::assertSame(1, $report->skipped);
        self::assertSame($mine->getId(), $report->items[0]->itemId);
        self::assertNull($report->items[0]->reuseGroup, 'A password equal to an invisible item is not reported as reused.');
        self::assertNotContains($hidden->getId(), array_map(static fn (VaultHealthItemResult $r): string => $r->itemId, $report->items));
    }

    public function testBreachCheckerCacheAndUndecryptableItems(): void
    {
        $this->item('Breached', 'Password-123!');
        $this->item('Fine', $this->marker);
        $this->item('Failing', 'Failing-Checker-1');
        $broken                           = $this->item('Broken', 'x');
        $this->payloads[$broken->getId()] = ['__fail' => true];
        $checker                          = new class implements VaultBreachCheckerInterface {
            public int $calls = 0;

            public function isBreached(#[SensitiveParameter] string $password): bool
            {
                ++$this->calls;
                if ($password === 'Failing-Checker-1') {
                    throw new RuntimeException('range API down');
                }

                return $password === 'Password-123!';
            }
        };
        $cache    = new ArrayAdapter();
        $reporter = $this->reporter(breachChecker: $checker, cache: $cache);

        $report  = $reporter->forScope(new TestUser('1'), self::SCOPE);
        $byTitle = $this->byTitle($report->items);
        self::assertTrue($byTitle['Breached']->breached);
        self::assertFalse($byTitle['Fine']->breached);
        self::assertNull($byTitle['Failing']->breached);
        self::assertSame(1, $report->skipped, 'An undecryptable item is skipped, never reported.');
        self::assertSame(1, $report->breachedCount());

        $again = $reporter->forScope(new TestUser('1'), self::SCOPE);
        self::assertSame(3, $checker->calls, 'The second report comes from the cache.');
        self::assertEquals($report, $again);
        foreach ($cache->getValues() as $value) {
            self::assertStringNotContainsString($this->marker, (string) $value);
        }
    }

    public function testSizeLimitAndAccessChecks(): void
    {
        $this->item('A', 'x');
        $this->item('B', 'y');
        try {
            $this->reporter(maxItems: 1)->forScope(new TestUser('1'), self::SCOPE);
            self::fail('Too large');
        } catch (VaultHealthTooLargeException $exception) {
            self::assertStringContainsString('2 login items', $exception->getMessage());
        }

        $this->members['3'][self::SCOPE] = new VaultScopeAccess(VaultPermission::Read, health: false);
        $this->assertDenied(fn (): VaultHealthReport => $this->reporter()->forScope(new TestUser('3'), self::SCOPE));
        $this->assertDenied(fn (): VaultHealthReport => $this->reporter()->forScope(new TestUser('9'), self::SCOPE));

        $this->dispatcher->addListener(VaultEvents::HEALTH_ACCESS_CHECK, static function (VaultHealthAccessCheckEvent $event): void {
            self::assertSame('scope:org-1', $event->getOwnerKey());
            if ($event->getUser()->getUserIdentifier() === 'u3@example.com') {
                $event->grant();
            } else {
                $event->deny();
            }
        });
        self::assertSame(2, $this->reporter()->forScope(new TestUser('3', 'u3@example.com'), self::SCOPE)->examined);
        $this->assertDenied(fn (): VaultHealthReport => $this->reporter()->forScope(new TestUser('1'), self::SCOPE));
    }

    public function testPersonalVaultAndPolicies(): void
    {
        $owner                              = new TestUser('5');
        $items                              = [new VaultItem(VaultItemType::Login, 'Mine', $owner, 'c-personal')];
        $this->payloads[$items[0]->getId()] = ['password' => 'short'];
        $repository                         = $this->createStub(VaultItemRepositoryInterface::class);
        $repository->method('findByCreatorAndItemType')->willReturn($items);
        $crypto   = $this->crypto();
        $reporter = new VaultHealthReporter($repository, $this->guard(), $crypto, $this->dispatcher, new ConfigVaultPasswordPolicyResolver(new VaultPasswordPolicy(4, 1, null)), clock: $this->clock);

        $report = $reporter->forPersonalVault($owner);
        self::assertSame('user:5', $report->owner->key());
        self::assertFalse($report->items[0]->weak, 'Policy: 4 characters, 1 class.');
        self::assertFalse($report->items[0]->old, 'No max age.');
        self::assertTrue($reporter->forPersonalVault($owner, new VaultPasswordPolicy(20, 4))->items[0]->weak, 'An explicit policy wins.');

        $this->expectException(LogicException::class);
        $reporter->forScope(new TestUser('1'), self::SCOPE);
    }

    #[RunInSeparateProcess]
    public function testStrengthEvaluatorStrategy(): void
    {
        require_once __DIR__ . '/../../Fixtures/OptionalBundles/PasswordConditions.php';
        $this->item('Evaluated', 'abcdefghijkl');
        $evaluator = new class {
            public ?int $minLength = null;

            public function evaluate(?string $password, object $conditions): stdClass
            {
                $this->minLength = (new ReflectionProperty($conditions, 'minLength'))->getValue($conditions);

                return (object) ['missing' => ['require_digit', 'min_classes']];
            }
        };

        $report = $this->reporter(evaluator: $evaluator)->forScope(new TestUser('1'), self::SCOPE);
        self::assertSame(['require_digit', 'min_classes'], $report->items[0]->weakReasons);
        self::assertSame(12, $evaluator->minLength);
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertDenied(callable $call): void
    {
        try {
            $call();
            self::fail('Denied expected');
        } catch (VaultAccessDeniedException) {
            self::addToAssertionCount(1);
        }
    }

    /**
     * @param list<VaultHealthItemResult> $items
     *
     * @return array<string, VaultHealthItemResult>
     */
    private function byTitle(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $map[$item->title] = $item;
        }

        return $map;
    }

    private function item(string $title, string $password, ?string $secretAge = null): VaultItem
    {
        $item = VaultItem::forScope(VaultItemType::Login, $title, self::SCOPE, null, 'c-' . $title);
        $item->markSecretChanged($secretAge !== null ? $this->clock->now()->modify($secretAge) : $this->clock->now());
        $this->payloads[$item->getId()] = ['password' => $password];
        $this->items[]                  = $item;

        return $item;
    }

    private function crypto(): VaultPayloadCryptographerInterface
    {
        $crypto = $this->createStub(VaultPayloadCryptographerInterface::class);
        $crypto->method('decrypt')->willReturnCallback(function (string $ciphertext): array {
            foreach ([...$this->items] as $item) {
                if ($item->getCiphertext() === $ciphertext) {
                    $payload = $this->payloads[$item->getId()];
                    if (isset($payload['__fail'])) {
                        throw new RuntimeException('Vault decryption failed.');
                    }

                    return $payload;
                }
            }

            return $this->payloads[array_key_first($this->payloads)];
        });

        return $crypto;
    }

    private function guard(): VaultAccessGuard
    {
        $resolver = new class(fn (string $id, string $scope): ?VaultScopeAccess => $this->members[$id][$scope] ?? null) implements VaultScopeAccessResolverInterface {
            public function __construct(private readonly Closure $lookup)
            {
            }

            public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
            {
                return ($this->lookup)($user instanceof TestUser ? $user->getId() : '', $scope);
            }
        };

        return new VaultAccessGuard($this->grants, new NullVaultTeamMembershipResolver(), $this->dispatcher, new RequestScopedScopeAccessCache($resolver), $this->clock);
    }

    private function reporter(?VaultBreachCheckerInterface $breachChecker = null, ?ArrayAdapter $cache = null, int $maxItems = 2000, ?object $evaluator = null): VaultHealthReporter
    {
        $repository = $this->createStubForIntersectionOfInterfaces([VaultItemRepositoryInterface::class, VaultScopeItemRepositoryInterface::class]);
        $repository->method('findActiveByScope')->willReturnCallback(fn (): array => $this->items);

        return new VaultHealthReporter(
            $repository,
            $this->guard(),
            $this->crypto(),
            $this->dispatcher,
            new ConfigVaultPasswordPolicyResolver(),
            $breachChecker,
            $evaluator,
            $cache,
            $cache instanceof ArrayAdapter ? 300 : 0,
            $maxItems,
            $this->clock,
            $this->logger,
        );
    }
}

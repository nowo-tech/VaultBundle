<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultBrowserExtensionAuthEvent;
use Nowo\VaultBundle\Event\VaultCatalogueAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFieldRevealCheckEvent;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Event\VaultGrantPolicyCheckEvent;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Event\VaultItemRevealedEvent;
use Nowo\VaultBundle\Event\VaultRevealThrottledEvent;
use Nowo\VaultBundle\Exception\VaultDecryptionFailedException;
use Nowo\VaultBundle\Exception\VaultRevealRefusedException;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultRevealRateLimiter;
use Nowo\VaultBundle\Security\VaultSecretAccessRateLimiter;
use Nowo\VaultBundle\Service\VaultFieldRevealer;
use Nowo\VaultBundle\Support\VaultRevealedValue;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\EventDispatcher\Event;

use function base64_encode;
use function bin2hex;
use function json_encode;
use function random_bytes;
use function serialize;
use function var_export;

/**
 * VaultFieldRevealer (1.16.0 §6.3) and the "no value in events, logs or exceptions" sweep of the reveal path.
 */
final class VaultFieldRevealerTest extends TestCase
{
    private string $marker;
    private EventDispatcher $dispatcher;
    private RecordingLogger $logger;

    /** @var list<Event> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->marker     = 'MARK' . bin2hex(random_bytes(16));
        $this->dispatcher = new EventDispatcher();
        $this->logger     = new RecordingLogger();
        foreach ([VaultEvents::ITEM_REVEALED, VaultEvents::ITEM_COPIED, VaultEvents::ITEM_ATTACHMENT_DOWNLOADED, VaultEvents::ITEM_FIELD_REVEAL_CHECK, VaultEvents::REVEAL_THROTTLED] as $name) {
            $this->dispatcher->addListener($name, function (Event $event): void {
                $this->events[] = $event;
            }, -100);
        }
    }

    public function testRevealCopyWebsitesAndAttachment(): void
    {
        $revealer = $this->revealer(['login' => ['password', 'websites', 'username']]);
        $login    = new VaultItem(VaultItemType::Login, 'Mail', new TestUser('1'), 'c');

        self::assertSame($this->marker, $revealer->reveal(new TestUser('1'), $login, 'password')->get());
        self::assertSame([$this->marker, 'b'], $revealer->reveal(new TestUser('1'), $login, 'websites', 'copy')->get());
        self::assertSame('', $revealer->reveal(new TestUser('1'), $login, 'username', 'other')->get(), 'Unknown purpose = reveal; non-string value = empty.');
        self::assertSame(['password', 'websites', 'username'], $revealer->revealableFields(VaultItemType::Login));
        self::assertSame(['cardNumber', 'cvv', 'cardPin', 'note'], $revealer->revealableFields(VaultItemType::CreditCard));

        $passport   = new VaultItem(VaultItemType::Passport, 'P', new TestUser('1'), 'c');
        $attachment = $revealer->attachment(new TestUser('1'), $passport);
        self::assertSame('../scan.pdf', $attachment['name']);
        self::assertSame('%PDF ' . $this->marker, $attachment['content']->get());

        $names = array_map(static fn (Event $e): string => $e::class, $this->events);
        self::assertContains(VaultItemRevealedEvent::class, $names);
        foreach ($this->events as $event) {
            self::assertStringNotContainsString($this->marker, serialize($event));
            self::assertStringNotContainsString($this->marker, var_export($event, true));
            self::assertStringNotContainsString($this->marker, (string) json_encode((array) $event));
        }
        self::assertStringNotContainsString($this->marker, serialize($this->logger->records));
        self::assertSame(['item_reveal', 'item_copy', 'item_reveal', 'item_attachment'], array_column(array_column($this->logger->records, 'context'), 'action'));
    }

    public function testRefusals(): void
    {
        $revealer = $this->revealer();
        $user     = new TestUser('1');
        $login    = new VaultItem(VaultItemType::Login, 'Mail', $user, 'c');

        foreach ([
            static fn (): VaultRevealedValue => $revealer->reveal($user, $login, 'username'),
            static fn (): array => $revealer->attachment($user, $login),
            fn (): array => $this->revealer(attachment: false)->attachment($user, new VaultItem(VaultItemType::Passport, 'P', $user, 'c')),
        ] as $call) {
            try {
                $call();
                self::fail('Refusal expected');
            } catch (VaultRevealRefusedException $exception) {
                self::assertSame(VaultRevealRefusedException::NOT_REVEALABLE, $exception->getReason());
                self::assertNull($exception->getWindow());
                self::assertSame(0, $exception->getRetryAfter());
            }
        }

        $crypto = $this->createStub(VaultPayloadCryptographerInterface::class);
        $crypto->method('decrypt')->willThrowException(new RuntimeException('secretbox failed for ' . $this->marker));
        try {
            (new VaultFieldRevealer($crypto, $this->dispatcher))->reveal($user, $login, 'password');
            self::fail('Decryption failure expected');
        } catch (VaultDecryptionFailedException $exception) {
            self::assertSame('Vault item could not be decrypted.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testVetoThrottleAndAuditFailure(): void
    {
        $user  = new TestUser('1');
        $login = new VaultItem(VaultItemType::Login, 'Mail', $user, 'c');

        $this->dispatcher->addListener(VaultEvents::ITEM_FIELD_REVEAL_CHECK, static function (VaultFieldRevealCheckEvent $event): void {
            if ($event->getField() === 'note') {
                $event->deny();
            }
        });
        $this->assertRefused(VaultRevealRefusedException::NOT_REVEALABLE, fn (): VaultRevealedValue => $this->revealer()->reveal($user, $login, 'note'));

        $secretAccess = new VaultSecretAccessRateLimiter(new ArrayAdapter(), 1, 60, true);
        $revealer     = new VaultFieldRevealer($this->crypto(), $this->dispatcher, null, $secretAccess, null, $this->logger);
        $revealer->reveal($user, $login, 'password');
        $refused = $this->assertRefused(VaultRevealRefusedException::THROTTLED, static fn (): VaultRevealedValue => $revealer->reveal($user, $login, 'password'));
        self::assertSame(VaultRevealThrottledEvent::WINDOW_SECRET_ACCESS, $refused->getWindow());
        self::assertSame(60, $refused->getRetryAfter());

        $limited = new VaultFieldRevealer($this->crypto(), $this->dispatcher, new VaultRevealRateLimiter(new ArrayAdapter(), 1, 10, 10), null, null, $this->logger);
        $limited->reveal($user, $login, 'password');
        self::assertSame(VaultRevealThrottledEvent::WINDOW_MINUTE, $this->assertRefused(VaultRevealRefusedException::THROTTLED, static fn (): VaultRevealedValue => $limited->reveal($user, $login, 'password'))->getWindow());
        self::assertSame('warning', $this->logger->records[array_key_last($this->logger->records)]['level']);

        $this->dispatcher->addListener(VaultEvents::ITEM_REVEALED, static function (): never {
            throw new RuntimeException('audit store down');
        });
        $this->assertRefused(VaultRevealRefusedException::AUDIT_FAILED, fn (): VaultRevealedValue => $this->revealer()->reveal($user, $login, 'password'));
        $last = $this->logger->records[array_key_last($this->logger->records)];
        self::assertSame('error', $last['level']);
        self::assertSame(RuntimeException::class, $last['context']['exception']);
    }

    public function testRevealedEventDefaultsAndNoValueProperty(): void
    {
        $web       = new VaultItemRevealedEvent('1', 'i', VaultItemType::Login, ['password'], VaultItemRevealedEvent::CHANNEL_WEB);
        $extension = new VaultItemRevealedEvent('1', 'i', VaultItemType::Login, ['password'], VaultItemRevealedEvent::CHANNEL_EXTENSION);
        self::assertSame('reveal', $web->getPurpose());
        self::assertSame('autofill', $extension->getPurpose());
        self::assertNull($web->getOwnerKey());
        self::assertNull($web->getOccurredAt());

        // Reflection: no event class has a property that could hold a value, a ciphertext or a hash.
        $finder = (new Finder())->files()->in(__DIR__ . '/../../../src/Event')->name('*Event.php');
        $seen   = 0;
        foreach ($finder as $file) {
            $class = 'Nowo\\VaultBundle\\Event\\' . $file->getBasename('.php');
            if ($class === VaultBrowserExtensionAuthEvent::class) {
                continue; // the extension login event carries the typed credentials to host authenticators by design
            }
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertDoesNotMatchRegularExpression('/^(value|values|plaintext|password|secret|ciphertext|cipher|hash|payload|content|fingerprint)$/i', $property->getName(), $class . '::$' . $property->getName());
            }
            ++$seen;
        }
        self::assertGreaterThanOrEqual(13, $seen);
        foreach ([VaultCatalogueAccessCheckEvent::class, VaultGrantChangeEvent::class, VaultGrantPolicyCheckEvent::class, VaultItemChangeEvent::class] as $class) {
            self::assertTrue((new ReflectionClass($class))->isFinal());
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertRefused(string $reason, callable $call): VaultRevealRefusedException
    {
        try {
            $call();
        } catch (VaultRevealRefusedException $exception) {
            self::assertSame($reason, $exception->getReason());
            self::assertStringNotContainsString($this->marker, $exception->getMessage());

            return $exception;
        }

        self::fail('Refusal expected: ' . $reason);
    }

    /**
     * @param array<string, list<string>>|null $revealable
     */
    private function revealer(?array $revealable = null, bool $attachment = true): VaultFieldRevealer
    {
        return new VaultFieldRevealer($this->crypto($attachment), $this->dispatcher, null, null, null, $this->logger, $revealable);
    }

    private function crypto(bool $attachment = true): VaultPayloadCryptographerInterface
    {
        $crypto = $this->createStub(VaultPayloadCryptographerInterface::class);
        $crypto->method('decrypt')->willReturn([
            'password'          => $this->marker,
            'username'          => 42,
            'websites'          => [$this->marker, 'b', 7],
            'note'              => 'n',
            'attachmentName'    => '../scan.pdf',
            'attachmentContent' => $attachment ? base64_encode('%PDF ' . $this->marker) : '',
        ]);

        return $crypto;
    }
}

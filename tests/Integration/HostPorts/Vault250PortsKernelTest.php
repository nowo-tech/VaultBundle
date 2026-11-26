<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\HostPorts;

use DateTimeImmutable;
use Nowo\VaultBundle\Dto\VaultCatalogueEntry;
use Nowo\VaultBundle\Dto\VaultCatalogueQuery;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemCreateCheckEvent;
use Nowo\VaultBundle\Exception\VaultItemCreateDeniedException;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Service\VaultCatalogue;
use Nowo\VaultBundle\Service\VaultExtensionTokenCommandsInterface;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\View\VaultExtensionTokenView;
use Nowo\VaultBundle\View\VaultItemView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;
use function assert;
use function bin2hex;
use function hash;
use function random_bytes;
use function strtoupper;

/**
 * 2.5.0 in both user mappings (standard host: relation and attributes; DDD host: id and XML): the catalogue creator
 * filter, the item count of ITEM_CREATE_CHECK and the extension token port compare user ids exactly.
 */
#[Group('database')]
final class Vault250PortsKernelTest extends KernelTestCase
{
    use HostPortsSupport;

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function variants(): iterable
    {
        yield 'relation mapping' => ['private_relation_new'];
        yield 'id mapping, XML' => ['private_idxml_new'];
    }

    #[Test]
    #[DataProvider('variants')]
    public function theCatalogueFiltersByTheExactCreator(string $variant): void
    {
        $this->bootVariant($variant);
        $this->items()->createInScope($this->ref('alice'), 'org-1', VaultItemType::Login, 'Router', ['password' => 'REDACTED-TEST']);
        $this->items()->createInScope($this->ref('alice'), 'org-1', VaultItemType::Login, 'Printer', ['password' => 'REDACTED-TEST']);
        // Dave (Read member, no create) is recorded as the creator of the second item, as after a role change.
        $this->container->get('doctrine')->getConnection()->executeStatement("UPDATE vault_items SET created_by_id = ? WHERE title = 'Printer'", [$this->id('dave')]);

        $titles = fn (?string $owner): array => array_map(static fn (VaultCatalogueEntry $e): string => $e->title, $this->catalogue()->page($this->users['alice'], 'org-1', new VaultCatalogueQuery(ownerId: $owner))->entries);
        self::assertEqualsCanonicalizing(['Router', 'Printer'], $titles(null));
        self::assertSame(['Router'], $titles($this->id('alice')));
        self::assertSame(['Printer'], $titles($this->id('dave')));
        // Look-alike ids match nothing (trailing space: PAD SPACE collations; case: case-insensitive ones; leading zero).
        foreach ([$this->id('alice') . ' ', '0' . $this->id('alice'), strtoupper($this->id('alice'))] as $lookAlike) {
            if ($lookAlike !== $this->id('alice')) {
                self::assertSame([], $titles($lookAlike), $lookAlike);
            }
        }
        self::assertSame([], $titles($this->id('bob')), 'A user without items.');
    }

    #[Test]
    #[DataProvider('variants')]
    public function theCreateCheckCountsOnlyTheOwnersItems(string $variant): void
    {
        $this->bootVariant($variant);
        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'A1', []);
        $this->items()->create($this->ref('bob'), VaultItemType::Login, 'B1', []);
        $this->items()->create($this->ref('bob'), VaultItemType::Login, 'B2', []);
        $this->items()->createInScope($this->ref('alice'), 'org-1', VaultItemType::Login, 'S1', []);

        $counts = [];
        $this->container->get('event_dispatcher')->addListener(VaultEvents::ITEM_CREATE_CHECK, static function (VaultItemCreateCheckEvent $event) use (&$counts): void {
            $counts[$event->getOwnerKey()] = $event->itemCount();
            if ($event->itemCount() >= 2) {
                $event->deny('plan_limit');
            }
        });

        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'A2', []);
        $this->assertThrows(fn (): VaultItemView => $this->items()->create($this->ref('bob'), VaultItemType::Login, 'B3', []));
        $this->items()->createInScope($this->ref('alice'), 'org-1', VaultItemType::Login, 'S2', []);
        $this->assertThrows(fn (): VaultItemView => $this->items()->createInScope($this->ref('alice'), 'org-1', VaultItemType::Login, 'S3', []));
        self::assertSame(['user:' . $this->id('alice') => 1, 'user:' . $this->id('bob') => 2, 'scope:org-1' => 2], $counts);
    }

    #[Test]
    #[DataProvider('variants')]
    public function theTokenPortSeesOnlyTheUsersOwnTokens(string $variant): void
    {
        $this->bootVariant($variant);
        $mine   = $this->token($this->users['alice']);
        $theirs = $this->token($this->users['bob']);
        $tokens = $this->container->get('test.port.' . VaultExtensionTokenCommandsInterface::class);
        assert($tokens instanceof VaultExtensionTokenCommandsInterface);

        self::assertSame([$mine], array_map(static fn (VaultExtensionTokenView $t): string => $t->id, $tokens->listForUser($this->ref('alice'))));
        self::assertFalse($tokens->revoke($this->ref('alice'), $theirs));
        self::assertTrue($tokens->revoke($this->ref('alice'), $mine));
        self::assertSame(1, $tokens->revokeAll($this->ref('bob')));
        self::assertSame([], $tokens->listForUser($this->ref('bob')));
    }

    private function catalogue(): VaultCatalogue
    {
        $catalogue = $this->container->get('test.port.' . VaultCatalogue::class);
        assert($catalogue instanceof VaultCatalogue);

        return $catalogue;
    }

    private function token(UserInterface $user): string
    {
        $users = $this->container->get('test.port.' . VaultUserReferenceResolverInterface::class);
        $repo  = $this->container->get('test.port.' . VaultExtensionTokenRepositoryInterface::class);
        assert($users instanceof VaultUserReferenceResolverInterface && $repo instanceof VaultExtensionTokenRepositoryInterface);
        $token = new VaultExtensionToken(hash('sha256', bin2hex(random_bytes(16))), new DateTimeImmutable('+1 day'), $users->reference($user));
        $repo->save($token);

        return $token->getId();
    }

    private function assertThrows(callable $call): void
    {
        try {
            $call();
            self::fail('Refused.');
        } catch (VaultItemCreateDeniedException $exception) {
            self::assertSame('plan_limit', $exception->getReason());
        }
    }
}

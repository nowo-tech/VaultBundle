<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\HostPorts;

use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\Tests\Support\ConcurrentWriter;
use Nowo\VaultBundle\View\VaultFolderView;
use Nowo\VaultBundle\View\VaultItemView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function assert;
use function getenv;
use function is_string;

/**
 * Audit YF-L1 (REQ-RUNTIME-003): a second connection deletes the row while a host command waits on its row lock. The
 * command then refuses (the row is gone), writes nothing and announces nothing: no zero-row update, no view or event
 * for a deleted resource, no foreign-key error. MySQL, MariaDB and PostgreSQL (`make test-with-db`).
 */
#[Group('database')]
final class ConcurrentCommandsDatabaseTest extends KernelTestCase
{
    use HostPortsSupport;

    private const PAYLOAD = ['password' => 'REDACTED-TEST'];

    private string $url = '';

    private int $changes = 0;

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    protected function setUp(): void
    {
        $url = getenv('VAULT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set VAULT_TEST_DATABASE_URL to run the two-connection tests on a database server.');
        }
        $this->url = $url;
        $this->bootVariant('private_relation_new');
        $dispatcher = $this->container->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        foreach ([VaultEvents::ITEM_CHANGED, VaultEvents::FOLDER_CHANGED, VaultEvents::TAG_CHANGED] as $event) {
            $dispatcher->addListener($event, function (): void {
                ++$this->changes;
            });
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function itemCommands(): iterable
    {
        foreach (['update', 'retag', 'move', 'trash', 'purge'] as $command) {
            yield $command => [$command];
        }
    }

    #[Test]
    #[DataProvider('itemCommands')]
    public function anItemDeletedWhileTheCommandWaitsIsRefused(string $command): void
    {
        $alice = $this->ref('alice');
        $item  = $this->items()->create($alice, VaultItemType::Login, 'Doomed', self::PAYLOAD, null, ['tag']);
        $this->changes = 0;

        $writer = ConcurrentWriter::start($this->url, [['DELETE FROM vault_items WHERE id = ?', [$item->id]]], 1.5);
        $this->assertThrows(VaultAccessDeniedException::class, fn (): ?VaultItemView => match ($command) {
            'update' => $this->items()->update($alice, $item->id, 'Lost', self::PAYLOAD),
            'retag'  => $this->items()->retag($alice, $item->id, ['other']),
            'move'   => $this->items()->move($alice, $item->id, null),
            'trash'  => $this->items()->moveToTrash($alice, $item->id),
            default  => $this->purge($item->id),
        }, $command);
        self::assertSame(0, $writer->finish(), 'the other connection committed');

        self::assertSame(0, $this->changes, 'no event for a deleted item');
        self::assertNull($this->query()->findById($item->id));
        self::assertSame(0, (int) self::hostManager($this->container)->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_items'));
    }

    #[Test]
    public function aFolderDeletedWhileTheCommandWaitsIsRefused(): void
    {
        $alice  = $this->ref('alice');
        $folder = $this->folders()->create($alice, 'Doomed');
        $this->changes = 0;

        $writer = ConcurrentWriter::start($this->url, [['DELETE FROM vault_folders WHERE id = ?', [$folder->id]]], 1.5);
        $this->assertThrows(VaultAccessDeniedException::class, fn (): VaultFolderView => $this->folders()->rename($alice, $folder->id, 'Lost'));
        self::assertSame(0, $writer->finish());

        self::assertSame(0, $this->changes);
        self::assertSame(0, (int) self::hostManager($this->container)->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_folders'));
    }

    private function purge(string $itemId): null
    {
        $this->items()->purge($this->ref('alice'), $itemId);

        return null;
    }
}

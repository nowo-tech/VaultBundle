<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\UserKitBundle\PersonalData\PersonalDataErasureInterface;
use Nowo\UserKitBundle\Transaction\UserKitStorageFailure;
use Nowo\VaultBundle\Bridge\UserKit\VaultUserKitEraser;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Tests\App\UserKitKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Nowo\VaultBundle\Tests\Support\ConcurrentWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Throwable;

use function getenv;
use function is_string;

/**
 * 2.5.0 (REQ-RUNTIME-003) on a database server: the vault erasure joins UserKit's transaction on MySQL, MariaDB and
 * PostgreSQL (DBAL 3 and 4 in CI). A later failing eraser and a lock wait both leave every vault row in place, no
 * transaction level open, and the storage failure is reported as such (never hidden by a lost savepoint); a retry
 * then erases everything.
 */
#[Group('database')]
final class VaultUserKitDatabaseTest extends WebTestCase
{
    use VaultWebTestTrait;

    private string $url;

    protected static function getKernelClass(): string
    {
        return UserKitKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    #[Test]
    public function theErasureJoinsUserKitsTransaction(): void
    {
        $this->boot();
        $alice = $this->createUser('alice@example.com');
        $item  = $this->createItem($alice, 'Mail', VaultItemType::Login, ['password' => 'Redacted-Test-1']);
        $this->createItem($alice, 'Bank');
        $id = (string) $alice->getId();
        $db = $this->em()->getConnection();

        // A later eraser fails: UserKit rolls back, the vault rows are back.
        try {
            $this->eraser()->erase($this->managed($alice), null, ['fail' => true]);
            self::fail('The host eraser fails.');
        } catch (RuntimeException $exception) {
            self::assertSame('host eraser failed', $exception->getMessage());
        }
        self::assertFalse($db->isTransactionActive());
        self::assertSame(2, $this->vaultItems($id));

        // Another connection holds a lock on one of the rows: the erasure fails with the lock error, nothing erased.
        ConcurrentWriter::setLockTimeout($db, 1);
        $holder = ConcurrentWriter::hold($this->url);
        $holder->executeQuery('SELECT id FROM vault_items WHERE id = ? FOR UPDATE', [$item->getId()]);
        try {
            $this->eraser()->erase($this->managed($alice));
            self::fail('The erasure waits for the lock and fails.');
        } catch (Throwable $exception) {
            self::assertTrue($this->isRetryable($exception), 'The lock error is reported: ' . $exception::class . ': ' . $exception->getMessage());
        } finally {
            $holder->rollBack();
            $holder->close();
        }
        self::assertFalse($db->isTransactionActive(), 'No nesting level is left open.');
        self::assertSame(2, $this->vaultItems($id));

        // The retry on the same kernel erases everything.
        ConcurrentWriter::setLockTimeout($db, 10);
        self::assertContains(VaultUserKitEraser::KEY, $this->eraser()->erase($this->managed($alice)));
        self::assertSame(0, $this->vaultItems($id));
    }

    private function boot(): void
    {
        $url = getenv('VAULT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set VAULT_TEST_DATABASE_URL to run the concurrency tests on a database server.');
        }
        $this->url = $url;
        $client    = self::createClient(['environment' => 'database', 'debug' => false]);
        $client->disableReboot();
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    private function eraser(): PersonalDataErasureInterface
    {
        $eraser = self::getContainer()->get('test.user_kit.eraser');
        self::assertInstanceOf(PersonalDataErasureInterface::class, $eraser);

        return $eraser;
    }

    private function vaultItems(string $userId): int
    {
        $check = ConcurrentWriter::connect($this->url);
        $count = (int) $check->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [$userId]);
        $check->close();

        return $count;
    }

    private function isRetryable(Throwable $exception): bool
    {
        for ($e = $exception; $e instanceof Throwable; $e = $e->getPrevious()) {
            if ($e instanceof RetryableException || $e instanceof UserKitStorageFailure) {
                return true;
            }
        }

        return false;
    }
}

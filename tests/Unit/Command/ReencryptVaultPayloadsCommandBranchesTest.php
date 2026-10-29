<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Command;

use Nowo\VaultBundle\Command\ReencryptVaultPayloadsCommand;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Service\VaultPayloadReencryptionService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\RecordingLogger;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

use function count;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Remaining branches of nowo:vault:reencrypt and its log records (REQ-OBS-001: no key material).
 */
final class ReencryptVaultPayloadsCommandBranchesTest extends TestCase
{
    private string $oldKey;
    private string $newKey;
    private RecordingLogger $logger;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->oldKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $this->newKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testNewKeyFileErrorsAndInvalidNewKey(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--old-key' => $this->oldKey, '--new-key-file' => '/no/such/new-key-file', '--dry-run' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/not\s+readable/', $tester->getDisplay());

        $tester->execute(['--old-key' => $this->oldKey, '--new-key-file' => $this->keyFile('not-a-key'), '--dry-run' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/32-byte libsodium\s+key/', $tester->getDisplay());
    }

    public function testPersistNewKeyGuards(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--old-key' => $this->oldKey, '--persist-new-key' => true, '--force' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('requires --new-key or --new-key-file', $tester->getDisplay());

        $tester->execute(['--old-key' => $this->oldKey, '--new-key' => $this->newKey, '--persist-new-key' => true, '--dry-run' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());

        $tester->execute(['--old-key' => $this->oldKey, '--new-key' => $this->newKey, '--persist-new-key' => true, '--force' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('requires nowo_vault.config_storage.enabled', $tester->getDisplay());
        self::assertSame([], $this->logger->records, 'guards fail before the run starts');
    }

    public function testInteractiveConfirmation(): void
    {
        $repository = $this->repositoryWith([$this->item($this->oldKey)]);
        $repository->expects(self::once())->method('saveBatch');
        $tester = new CommandTester($this->command($repository));

        $tester->setInputs(['no']);
        $tester->execute(['--old-key' => $this->oldKey, '--new-key' => $this->newKey], ['interactive' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $this->logger->records);

        $tester->setInputs(['yes']);
        $tester->execute(['--old-key' => $this->oldKey, '--new-key-file' => $this->keyFile($this->newKey . "\n")], ['interactive' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Re-encrypted 1 of 1', $tester->getDisplay());
        self::assertStringContainsString('Remember to update nowo_vault.encryption_key', $tester->getDisplay());
        self::assertSame(['Vault payload re-encryption started.', 'Vault payload re-encryption finished.'], $this->logger->messages());
        self::assertSame(['bundle' => 'nowo_vault', 'action' => 'reencrypt', 'dry_run' => false, 'include_deleted' => true, 'total' => 1, 'processed' => 1, 'skipped' => 0], $this->logger->records[1]['context']);
        $this->assertNoKeyInLogs();
    }

    public function testVerboseProgressBarAndEmptyVault(): void
    {
        $tester = new CommandTester($this->command($this->repositoryWith([])));
        $tester->execute(
            ['--old-key' => $this->oldKey, '--new-key' => $this->newKey, '--dry-run' => true, '--skip-trash' => true],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE, 'decorated' => true],
        );
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No vault items found.', $tester->getDisplay());
        self::assertFalse($this->logger->records[0]['context']['include_deleted']);

        $tester = new CommandTester($this->command($this->repositoryWith([$this->item($this->oldKey)])));
        $tester->execute(
            ['--old-key' => $this->oldKey, '--new-key' => $this->newKey, '--dry-run' => true],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE, 'decorated' => true],
        );
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1/1', $tester->getDisplay());
        self::assertStringContainsString('Verified 1 of 1', $tester->getDisplay());
    }

    public function testServiceRejectionIsInvalidAndLogged(): void
    {
        // Same old and new key: the service refuses with InvalidArgumentException.
        $tester = new CommandTester($this->command($this->repositoryWith([])));
        $tester->execute(['--old-key' => $this->oldKey, '--new-key' => $this->oldKey, '--force' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Old and new encryption keys must differ.', $tester->getDisplay());
        self::assertSame('Vault payload re-encryption rejected.', $this->logger->records[1]['message']);
        self::assertSame('error', $this->logger->records[1]['level']);
        $this->assertNoKeyInLogs();
    }

    public function testDecryptFailureIsFailureAndLogged(): void
    {
        $foreign = $this->item(SodiumVaultPayloadCryptographer::generateKeyBase64());
        $tester  = new CommandTester($this->command($this->repositoryWith([$foreign])));
        $tester->execute(['--old-key' => $this->oldKey, '--new-key' => $this->newKey, '--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed to decrypt vault item', $tester->getDisplay());
        self::assertSame('Vault payload re-encryption failed.', $this->logger->records[1]['message']);
        self::assertStringContainsString($foreign->getId(), $this->logger->records[1]['context']['error']);
        $this->assertNoKeyInLogs();
    }

    public function testPersistedKeyIsLoggedWithoutValue(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => $this->oldKey]);
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->method('findByScope')->willReturn(null);
        $writer = new VaultRuntimeConfigWriter(true, $settings, new VaultRuntimeConfigProvider($baseline, true, $settings, new ArrayAdapter()), true);

        $tester = new CommandTester($this->command($this->repositoryWith([$this->item($this->oldKey)]), $writer, true));
        $tester->execute(['--old-key' => $this->oldKey, '--new-key' => $this->newKey, '--persist-new-key' => true, '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('Vault encryption key replaced in database runtime config.', $this->logger->records[2]['message']);
        $this->assertNoKeyInLogs();
    }

    private function assertNoKeyInLogs(): void
    {
        self::assertStringNotContainsString($this->oldKey, $this->logger->dump());
        self::assertStringNotContainsString($this->newKey, $this->logger->dump());
    }

    private function keyFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'vault-key-');
        self::assertIsString($file);
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return $file;
    }

    private function item(string $key): VaultItem
    {
        return new VaultItem(
            VaultItemType::Login,
            'Demo',
            new TestUser('1'),
            (new SodiumVaultPayloadCryptographer($key))->encrypt(['username' => 'u', 'password' => 'REDACTED-TEST']),
        );
    }

    /**
     * @param list<VaultItem> $items
     *
     * @return MockObject&VaultItemRepositoryInterface
     */
    private function repositoryWith(array $items): VaultItemRepositoryInterface
    {
        $repository = $this->createMock(VaultItemRepositoryInterface::class);
        $repository->method('countAll')->willReturn(count($items));
        $repository->method('findBatch')->willReturn($items);

        return $repository;
    }

    private function command(
        ?VaultItemRepositoryInterface $repository = null,
        ?VaultRuntimeConfigWriter $writer = null,
        bool $databaseEnabled = false,
    ): ReencryptVaultPayloadsCommand {
        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => SodiumVaultPayloadCryptographer::generateKeyBase64()]);
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $provider = new VaultRuntimeConfigProvider($baseline, false, $settings, new ArrayAdapter());

        return new ReencryptVaultPayloadsCommand(
            new VaultPayloadReencryptionService(
                $repository ?? $this->createMock(VaultItemRepositoryInterface::class),
                new VaultRuntimeConfigResolver($provider, $baseline, false),
            ),
            $writer ?? new VaultRuntimeConfigWriter(false, $settings, $provider),
            $databaseEnabled,
            $this->logger,
        );
    }
}

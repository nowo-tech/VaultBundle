<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Command;

use Nowo\VaultBundle\Command\ReencryptVaultPayloadsCommand;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Service\VaultPayloadReencryptionService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReencryptVaultPayloadsCommandTest extends TestCase
{
    public function testRequiresOldKeyOption(): void
    {
        $tester = new CommandTester($this->createCommand());

        $tester->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--old-key option is required', $tester->getDisplay());
    }

    public function testRunsDryRunSuccessfully(): void
    {
        $oldKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $newKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $item   = new VaultItem(
            VaultItemType::Login,
            'Demo',
            new TestUser('1'),
            (new SodiumVaultPayloadCryptographer($oldKey))->encrypt(['username' => 'u', 'password' => 'p']),
        );

        $repository = $this->createMock(VaultItemRepositoryInterface::class);
        $repository->method('countAll')->willReturn(1);
        $repository->method('findBatch')->willReturn([$item]);

        $tester = new CommandTester($this->createCommand($repository, $newKey));
        $tester->execute([
            '--old-key'    => $oldKey,
            '--new-key'    => $newKey,
            '--batch-size' => '25',
            '--dry-run'    => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Verified 1 of 1', $tester->getDisplay());
    }

    public function testPersistNewKeyUpdatesRuntimeConfig(): void
    {
        $oldKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $newKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $item   = new VaultItem(
            VaultItemType::Login,
            'Demo',
            new TestUser('1'),
            (new SodiumVaultPayloadCryptographer($oldKey))->encrypt(['username' => 'u', 'password' => 'p']),
        );

        $repository = $this->createMock(VaultItemRepositoryInterface::class);
        $repository->method('countAll')->willReturn(1);
        $repository->method('findBatch')->willReturn([$item]);
        $repository->expects(self::once())->method('saveBatch');

        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => $oldKey]);
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->method('findByScope')->willReturn(null);
        $settings->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (VaultSettings $entity) use ($newKey): bool {
                self::assertSame($newKey, $entity->getEncryptionKey());

                return true;
            }));

        $provider = new VaultRuntimeConfigProvider($baseline, true, $settings, new ArrayAdapter());
        $writer   = new VaultRuntimeConfigWriter(true, $settings, $provider);

        $tester = new CommandTester($this->createCommand($repository, $newKey, $writer, databaseEnabled: true));
        $tester->execute([
            '--old-key'         => $oldKey,
            '--new-key'         => $newKey,
            '--persist-new-key' => true,
            '--force'           => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Stored the new encryption key', $tester->getDisplay());
    }

    public function testRequiresForceForNonInteractiveWrite(): void
    {
        $oldKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $newKey = SodiumVaultPayloadCryptographer::generateKeyBase64();

        $tester = new CommandTester($this->createCommand(encryptionKey: $newKey));
        $tester->execute([
            '--old-key' => $oldKey,
            '--new-key' => $newKey,
        ], ['interactive' => false]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('requires --force', $tester->getDisplay());
    }

    public function testRejectsInvalidOldKey(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute([
            '--old-key' => 'not-valid',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('base64-encoded', $tester->getDisplay());
    }

    private function createCommand(
        ?VaultItemRepositoryInterface $repository = null,
        ?string $encryptionKey = null,
        ?VaultRuntimeConfigWriter $writer = null,
        bool $databaseEnabled = false,
    ): ReencryptVaultPayloadsCommand {
        $baseline = VaultRuntimeConfigFactory::baseline([
            'encryption_key' => $encryptionKey ?? SodiumVaultPayloadCryptographer::generateKeyBase64(),
        ]);
        $resolver = new VaultRuntimeConfigResolver(
            new VaultRuntimeConfigProvider(
                $baseline,
                false,
                $this->createMock(VaultSettingsRepositoryInterface::class),
                new ArrayAdapter(),
            ),
            $baseline,
            false,
        );

        return new ReencryptVaultPayloadsCommand(
            new VaultPayloadReencryptionService(
                $repository ?? $this->createMock(VaultItemRepositoryInterface::class),
                $resolver,
            ),
            $writer ?? new VaultRuntimeConfigWriter(
                false,
                $this->createMock(VaultSettingsRepositoryInterface::class),
                new VaultRuntimeConfigProvider(
                    $baseline,
                    false,
                    $this->createMock(VaultSettingsRepositoryInterface::class),
                    new ArrayAdapter(),
                ),
            ),
            $databaseEnabled,
        );
    }
}

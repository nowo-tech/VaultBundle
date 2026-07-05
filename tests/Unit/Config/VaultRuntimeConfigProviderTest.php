<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Config;

use LogicException;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigSchema;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

final class VaultRuntimeConfigProviderTest extends TestCase
{
    public function testReturnsYamlBaselineWhenDatabaseDisabled(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline(['max_attachment_bytes' => 1024]);
        $provider = new VaultRuntimeConfigProvider(
            $baseline,
            false,
            $this->createMock(VaultSettingsRepositoryInterface::class),
            $this->createCache(),
        );

        self::assertSame(1024, $provider->get()['max_attachment_bytes']);
    }

    public function testMergesMissingRouteDefaults(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline([
            'routes' => [
                'index' => ['path' => '/custom', 'name' => 'custom_index'],
            ],
        ]);
        $provider = new VaultRuntimeConfigProvider(
            $baseline,
            false,
            $this->createMock(VaultSettingsRepositoryInterface::class),
            $this->createCache(),
        );

        $routes = $provider->get()['routes'];
        self::assertSame('/custom', $routes['index']['path']);
        self::assertSame('nowo_vault_item_view', $routes['item_view']['name']);
    }

    public function testMergesDatabaseOverridesAndCachesResult(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline(['max_attachment_bytes' => 512_000]);
        $stored   = new VaultSettings(values: ['max_attachment_bytes' => 256_000]);

        $repo = $this->createMock(VaultSettingsRepositoryInterface::class);
        $repo->expects(self::once())->method('findByScope')->willReturn($stored);

        $cache    = $this->createCache();
        $provider = new VaultRuntimeConfigProvider($baseline, true, $repo, $cache);

        self::assertSame(256_000, $provider->get()['max_attachment_bytes']);
        self::assertSame(256_000, $provider->get()['max_attachment_bytes']);
    }

    public function testInvalidateCacheForcesReload(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline();
        $stored   = new VaultSettings(values: ['max_attachment_bytes' => 100_000]);

        $repo = $this->createMock(VaultSettingsRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('findByScope')->willReturn($stored);

        $provider = new VaultRuntimeConfigProvider($baseline, true, $repo, $this->createCache());
        $provider->get();
        $provider->invalidateCache();
        self::assertSame(100_000, $provider->get()['max_attachment_bytes']);
    }

    public function testSchemaFiltersDisallowedKeys(): void
    {
        $filtered = VaultRuntimeConfigSchema::filter([
            'max_attachment_bytes' => 1,
            'encryption_key'       => 'secret',
            'user_class'           => 'App\\Entity\\User',
            'routes'               => ['index' => ['path' => '/x']],
            'password_field'       => [
                'level'               => 'strong',
                'generator_mode'      => 'modal',
                'use_password_toggle' => false,
            ],
        ]);

        self::assertSame(1, $filtered['max_attachment_bytes']);
        self::assertSame('secret', $filtered['encryption_key']);
        self::assertSame(['level' => 'strong'], $filtered['password_field']);
        self::assertArrayNotHasKey('routes', $filtered);
        self::assertArrayNotHasKey('user_class', $filtered);
    }

    public function testWriterPersistsAndInvalidatesCache(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline();
        $settings = new VaultSettings();

        $repo = $this->createMock(VaultSettingsRepositoryInterface::class);
        $repo->method('findByScope')->willReturn($settings);
        $repo->expects(self::once())->method('save')->with($settings);

        $cache    = $this->createCache();
        $provider = new VaultRuntimeConfigProvider($baseline, true, $repo, $cache);
        $writer   = new VaultRuntimeConfigWriter(true, $repo, $provider);

        $provider->get();
        $writer->update(['max_attachment_bytes' => 2048]);

        self::assertSame(2048, $settings->getValues()['max_attachment_bytes']);
    }

    public function testWriterThrowsWhenDatabaseStorageDisabled(): void
    {
        $writer = new VaultRuntimeConfigWriter(
            false,
            $this->createMock(VaultSettingsRepositoryInterface::class),
            new VaultRuntimeConfigProvider(
                VaultRuntimeConfigFactory::baseline(),
                false,
                $this->createMock(VaultSettingsRepositoryInterface::class),
                new ArrayAdapter(),
            ),
        );

        $this->expectException(LogicException::class);
        $writer->update(['max_attachment_bytes' => 1]);
    }

    public function testWriterResetClearsOverrides(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline();
        $settings = new VaultSettings(values: ['max_attachment_bytes' => 100], encryptionKey: 'db-key');

        $repo = $this->createMock(VaultSettingsRepositoryInterface::class);
        $repo->method('findByScope')->willReturn($settings);
        $repo->expects(self::once())->method('save')->with($settings);

        $provider = new VaultRuntimeConfigProvider($baseline, true, $repo, new ArrayAdapter());
        $writer   = new VaultRuntimeConfigWriter(true, $repo, $provider);

        $writer->reset();

        self::assertSame([], $settings->getValues());
        self::assertNull($settings->getEncryptionKey());
    }

    public function testWriterResetWithoutExistingRowInvalidatesCache(): void
    {
        $repo = $this->createMock(VaultSettingsRepositoryInterface::class);
        $repo->method('findByScope')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $provider = new VaultRuntimeConfigProvider(VaultRuntimeConfigFactory::baseline(), true, $repo, new ArrayAdapter());
        $writer   = new VaultRuntimeConfigWriter(true, $repo, $provider);

        $writer->reset();
        self::assertTrue(true);
    }

    public function testWriterPersistsEncryptionKeyOnDedicatedColumn(): void
    {
        $settings = new VaultSettings();
        $repo     = $this->createMock(VaultSettingsRepositoryInterface::class);
        $repo->method('findByScope')->willReturn($settings);
        $repo->expects(self::once())->method('save')->with($settings);

        $writer = new VaultRuntimeConfigWriter(
            true,
            $repo,
            new VaultRuntimeConfigProvider(VaultRuntimeConfigFactory::baseline(), true, $repo, new ArrayAdapter()),
        );

        $writer->update(['encryption_key' => 'base64-key']);

        self::assertSame('base64-key', $settings->getEncryptionKey());
        self::assertSame([], $settings->getValues());
    }

    public function testWriterIgnoresDisallowedKeys(): void
    {
        new VaultSettings();
        $repo = $this->createMock(VaultSettingsRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $writer = new VaultRuntimeConfigWriter(
            true,
            $repo,
            new VaultRuntimeConfigProvider(VaultRuntimeConfigFactory::baseline(), true, $repo, new ArrayAdapter()),
        );

        $writer->update(['user_class' => 'App\\Entity\\User']);
    }

    public function testWriterResetThrowsWhenDatabaseStorageDisabled(): void
    {
        $writer = new VaultRuntimeConfigWriter(
            false,
            $this->createMock(VaultSettingsRepositoryInterface::class),
            new VaultRuntimeConfigProvider(
                VaultRuntimeConfigFactory::baseline(),
                false,
                $this->createMock(VaultSettingsRepositoryInterface::class),
                new ArrayAdapter(),
            ),
        );

        $this->expectException(LogicException::class);
        $writer->reset();
    }

    private function createCache(): CacheInterface
    {
        return new ArrayAdapter();
    }
}

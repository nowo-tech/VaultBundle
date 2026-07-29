<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class VaultRuntimeConfigResolverTest extends TestCase
{
    public function testThrowsWhenEncryptionKeyMissing(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => '']);
        $provider = new VaultRuntimeConfigProvider(
            $baseline,
            false,
            $this->createMock(VaultSettingsRepositoryInterface::class),
            new ArrayAdapter(),
        );
        $resolver = new VaultRuntimeConfigResolver($provider, $baseline, false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nowo_vault.encryption_key is not configured in YAML or database.');

        $resolver->resolveEncryptionKeyBase64();
    }
}

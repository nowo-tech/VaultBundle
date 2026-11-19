<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use Nowo\VaultBundle\Bridge\SearchKit\VaultItemSearchDefinition;
use Nowo\VaultBundle\Bridge\SearchKit\VaultSearchViewers;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function class_exists;

/**
 * 1.21.0: the SearchKit group `vault_items` is opt-in: registered (tagged for SearchKit's provider pass) only with
 * `search_kit.enabled: true` and SearchKit installed.
 */
#[CoversClass(VaultExtension::class)]
final class VaultExtensionSearchKitTest extends TestCase
{
    private const BASE_CONFIG = [
        'user_class'     => 'App\\Entity\\User',
        'encryption_key' => 'REDACTED-TEST',
    ];

    public function testTheGroupIsTaggedWhenSearchKitIsInstalled(): void
    {
        $container = $this->load(['search_kit' => ['enabled' => true, 'priority' => 12, 'max_candidates' => 40], 'scopes' => ['enabled' => true, 'access_resolver' => 'app.scopes']]);
        if (!class_exists(VaultExtension::SEARCH_KIT_DEFINITION_CLASS)) {
            self::assertFalse($container->hasDefinition(VaultItemSearchDefinition::class), 'Without SearchKit (PHP 8.2) nothing is registered.');

            return;
        }

        $definition = $container->getDefinition(VaultItemSearchDefinition::class);
        self::assertSame([['priority' => 12]], $definition->getTag(VaultExtension::SEARCH_KIT_DEFINITION_TAG));
        self::assertSame(40, $definition->getArgument('$maxCandidates'));
        self::assertTrue($definition->getArgument('$scopesEnabled'));
        self::assertSame('nowo_vault.port.access_checker', (string) $definition->getArgument('$accessChecker'));
        self::assertSame('nowo_vault.port.team_membership', (string) $definition->getArgument('$teams'));
        self::assertTrue($container->hasDefinition(VaultSearchViewers::class));

        $defaults = $this->load(['search_kit' => ['enabled' => true]])->getDefinition(VaultItemSearchDefinition::class);
        self::assertSame([['priority' => 0]], $defaults->getTag(VaultExtension::SEARCH_KIT_DEFINITION_TAG));
        self::assertSame(100, $defaults->getArgument('$maxCandidates'));
        self::assertFalse($defaults->getArgument('$scopesEnabled'));
    }

    public function testTheGroupIsOptIn(): void
    {
        foreach ([[], ['search_kit' => ['enabled' => false]]] as $config) {
            $container = $this->load($config);

            self::assertFalse($container->hasDefinition(VaultItemSearchDefinition::class), 'Off by default (owner decision): hosts switch it on.');
            self::assertFalse($container->hasDefinition(VaultSearchViewers::class));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new VaultExtension())->load([self::BASE_CONFIG + $config], $container);

        return $container;
    }
}

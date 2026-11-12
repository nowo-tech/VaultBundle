<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use Nowo\VaultBundle\DependencyInjection\Compiler\VaultPortsPass;
use Nowo\VaultBundle\Port\VaultPortMode;
use Nowo\VaultBundle\Tests\App\Ports\BothHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\PassThroughLegacyDecorators;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function array_diff_assoc;
use function array_keys;
use function count;
use function implode;
use function sprintf;
use function str_contains;

/**
 * 1.19.0 host shapes in a real kernel: `lint:container` passes for every shape (deprecated ports only, 1.19 ports
 * only, a class implementing both, decorators of the deprecated ports, the built-ins, a DDD host with the id mapping
 * and the XML mapping), the build picks the expected interface(s), and every shape answers every page, list, share
 * page and reveal exactly as the 1.18 wiring of the same host (status codes, deny_status, which items each list
 * shows), with the 1.18 persistence and with the private entity manager.
 */
final class PortsKernelTest extends WebTestCase
{
    use VariantKernelSupport;

    private const ACCESS = ['access_checker', 'team_membership', 'scope_access'];

    /** @var array<string, array<string, string>> */
    private static array $baselines = [];

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function variants(): iterable
    {
        $legacy    = ['access_checker' => VaultPortMode::LEGACY, 'team_membership' => VaultPortMode::LEGACY, 'scope_access' => VaultPortMode::LEGACY, 'user_locator' => VaultPortMode::POLICY, 'health_reporter' => VaultPortMode::POLICY];
        $policy    = ['access_checker' => VaultPortMode::POLICY, 'team_membership' => VaultPortMode::POLICY, 'scope_access' => VaultPortMode::POLICY, 'user_locator' => VaultPortMode::POLICY, 'health_reporter' => VaultPortMode::POLICY];
        $decorated = ['access_checker' => VaultPortMode::BOTH, 'team_membership' => VaultPortMode::BOTH, 'scope_access' => VaultPortMode::BOTH] + $policy;
        $builtIn   = ['access_checker' => VaultPortMode::POLICY, 'team_membership' => VaultPortMode::POLICY, 'scope_access' => VaultPortMode::LEGACY] + $policy;

        yield '1.18 host, 1.18 persistence (reference)' => ['legacy_relation_legacy', 'legacy_relation_legacy', $legacy];
        yield '1.18 host, private manager' => ['private_relation_legacy', 'legacy_relation_legacy', $legacy];
        yield '1.19 ports only' => ['legacy_relation_new', 'legacy_relation_legacy', $policy];
        yield '1.19 ports only, private manager' => ['private_relation_new', 'legacy_relation_legacy', $policy];
        yield 'both interfaces' => ['private_relation_both', 'legacy_relation_legacy', $policy];
        yield 'deprecated-port decorators' => ['legacy_relation_decorator', 'legacy_relation_legacy', $decorated];
        yield 'deprecated-port decorators, private manager' => ['private_relation_decorator', 'legacy_relation_legacy', $decorated];
        yield 'XML mapping, private manager' => ['private_relxml_new', 'legacy_relation_legacy', $policy];
        yield 'built-ins (reference)' => ['legacy_relation_builtin', 'legacy_relation_builtin', $builtIn];
        yield 'built-ins, private manager' => ['private_relation_builtin', 'legacy_relation_builtin', $builtIn];
        yield 'deny_status 404 (reference)' => ['legacy_relation_legacy_404', 'legacy_relation_legacy_404', $legacy];
        yield 'deny_status 404, 1.19 ports, private manager' => ['private_relation_new_404', 'legacy_relation_legacy_404', $policy];
        yield 'id mapping, 1.18 host (reference)' => ['legacy_id_legacy', 'legacy_id_legacy', ['user_locator' => VaultPortMode::LEGACY] + $legacy];
        yield 'DDD host: id + XML mapping, 1.19 ports only, private manager' => ['private_idxml_new', 'legacy_id_legacy', $policy];
        yield 'id mapping, decorators, private manager' => ['private_id_decorator', 'legacy_id_legacy', $decorated];
    }

    /**
     * @param array<string, string> $modes
     */
    #[Test]
    #[DataProvider('variants')]
    public function everyHostShapeLintsAndDecidesLikeThe118Wiring(string $variant, string $reference, array $modes): void
    {
        $baseline = self::$baselines[$reference] ??= $this->decisions($reference);

        $decisions = $this->decisions($variant);
        $container = self::getContainer();
        foreach ($modes as $port => $mode) {
            self::assertSame($mode, $container->getParameter(VaultPortsPass::PARAMETER_PREFIX . $port . '.mode'), $variant . ': ' . $port);
        }

        self::assertGreaterThan(60, count($baseline));
        self::assertSame([], array_diff_assoc($baseline, $decisions), sprintf('%s differs from %s: %s', $variant, $reference, implode(', ', array_keys(array_diff_assoc($baseline, $decisions)))));
        self::assertSame($baseline, $decisions);

        if (str_contains($variant, 'decorator')) {
            foreach (['access', 'teams', 'scopes'] as $name) {
                $decorator = $container->get('app.vault_audit.' . $name);
                self::assertInstanceOf(PassThroughLegacyDecorators::class, $decorator);
                self::assertGreaterThan(0, $decorator->calls, 'the decorator of the deprecated ' . $name . ' port runs');
            }
        }
        if (str_contains($variant, '_both')) {
            $host = $container->get('app.vault_ports');
            self::assertInstanceOf(BothHostPorts::class, $host);
            self::assertSame(0, $host->calls['legacy'], 'a class implementing both interfaces is asked through the 1.19 one');
            self::assertGreaterThan(0, $host->calls['policy']);
        }
    }

    /**
     * Boots the variant, lints its container, seeds the same rows and returns its decisions.
     *
     * @return array<string, string>
     */
    private function decisions(string $variant): array
    {
        self::ensureKernelShutdown();
        $client = self::createClient(['environment' => $variant, 'debug' => true]);
        $client->disableReboot();

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('lint:container'));
        self::assertSame(0, $tester->execute([]), $variant . ': ' . $tester->getDisplay());

        $container = self::getContainer();
        self::resetSchema($container);
        $byId  = str_contains($variant, '_id');
        $users = self::seedUsers($container, $byId);
        $ids   = self::seedVault($container, $users);

        $decisions = self::requestMatrix($client, $users, $ids);
        foreach (self::ACCESS as $port) {
            self::assertTrue($container->hasParameter(VaultPortsPass::PARAMETER_PREFIX . $port . '.mode'));
        }

        return $decisions;
    }
}

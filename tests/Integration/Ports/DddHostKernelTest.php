<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as BundleMappingDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\Service\VaultPersonalDataEraserInterface;
use Nowo\VaultBundle\Service\VaultPersonalDataExporterInterface;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Tests\App\Ports\PolicyHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectory;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectoryV119;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function assert;

/**
 * 1.19.0 strict DDD / hexagonal host: plain PHP security users with UUID ids (`user_mapping: id`), the vault entities
 * mapped from the shipped XML, the private entity manager, and only the framework-free 1.19 ports implemented (no
 * Symfony type in the host's port classes). The host drives the vault through id-based ports, and the GDPR ports work
 * by id.
 */
final class DddHostKernelTest extends WebTestCase
{
    use VariantKernelSupport;

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    #[Test]
    public function theHostUsesOnlyTheFrameworkFreePortsAndTheXmlMapping(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient(['environment' => 'private_idxml_new', 'debug' => false]);
        $client->disableReboot();
        $container = self::getContainer();
        self::resetSchema($container);

        // Only the 1.19 ports are served by the host; no deprecated alias names another implementation.
        self::assertInstanceOf(PolicyHostPorts::class, $container->get('test.port.' . VaultAccessPolicyInterface::class));
        self::assertInstanceOf(PortsUserDirectoryV119::class, $container->get('test.port.' . VaultUserDirectoryInterface::class));

        // The vault entities come from the XML mapping, in the vault's own manager.
        $manager = self::vaultManager($container);
        $chain   = $manager->getConfiguration()->getMetadataDriverImpl();
        if ($chain instanceof BundleMappingDriver) {
            $chain = $chain->getDriver();
        }
        self::assertInstanceOf(MappingDriverChain::class, $chain);
        self::assertInstanceOf(SimplifiedXmlDriver::class, $chain->getDrivers()['Nowo\\VaultBundle\\Entity'] ?? null);
        self::assertSame('string', $manager->getClassMetadata(VaultItem::class)->getTypeOfField('creator'), 'id mapping: the creator is a string column');

        $users = self::seedUsers($container, true);
        $ids   = self::seedVault($container, $users);

        // A host use case acting for a user known by id only.
        $alice   = VaultUserRef::ofId(PortsUserDirectory::USERS['alice@example.com'], 'alice@example.com', ['ROLE_USER']);
        $health  = $container->get('test.port.' . VaultHealthReportProviderInterface::class);
        assert($health instanceof VaultHealthReportProviderInterface);
        self::assertSame(2, $health->reportForPersonalVault($alice)->examined, 'the two personal login items of alice');

        // Pages decide through the host's 1.19 ports.
        $client->loginUser($users['bob']);
        $client->request('GET', '/tools/vault/items/' . $ids['personal']);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'user grant by id');
        $client->loginUser($users['carol']);
        $client->request('GET', '/tools/vault/items/' . $ids['personal'] . '/edit');
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'team grant (Write) through teamIdsOf()');
        $client->loginUser($users['frank']);
        $client->request('GET', '/tools/vault/items/' . $ids['scope']);
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'non-member: the scope gate wins over the grant');
        $client->loginUser($users['dave']);
        $client->request('GET', '/tools/vault/items/new/login');
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'allowsCreate() refuses dave');

        // GDPR by id, through the 1.19 directory (no Symfony user object).
        $exporter = $container->get('test.port.' . VaultPersonalDataExporterInterface::class);
        $eraser   = $container->get('test.port.' . VaultPersonalDataEraserInterface::class);
        assert($exporter instanceof VaultPersonalDataExporterInterface && $eraser instanceof VaultPersonalDataEraserInterface);
        self::assertCount(2, $exporter->export((string) $alice->id)['items']);
        self::assertSame(2, $eraser->erase((string) $alice->id)->items);
        self::assertSame([], $exporter->export((string) $alice->id)['items']);
    }
}

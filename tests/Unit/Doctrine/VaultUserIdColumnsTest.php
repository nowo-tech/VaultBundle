<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Tests\App\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.17.0 id mapping (LQ-V-M1, LQ-V-I1): binary collation by platform and an index on every user id column; binary NO
 * PAD since 1.19.3 (REQ-SEC-006).
 */
final class VaultUserIdColumnsTest extends TestCase
{
    public function testBinaryCollationPerPlatform(): void
    {
        // REQ-SEC-006 (1.19.3): binary NO PAD, so `usera ` never equals `usera`; PAD SPACE utf8mb4_bin on MySQL 5.7 only
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'], $this->map(new MySQL80Platform())->fieldMappings['user']->options);
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'], $this->map(new MySQL84Platform())->fieldMappings['user']->options);
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_nopad_bin'], $this->map(new MariaDBPlatform())->fieldMappings['user']->options);
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'], $this->map(new MySQLPlatform())->fieldMappings['user']->options);
        self::assertSame(['collation' => 'Latin1_General_100_BIN2'], $this->map(new SQLServerPlatform())->fieldMappings['user']->options);
        self::assertNull($this->map(new PostgreSQLPlatform())->fieldMappings['user']->options);
    }

    public function testTheServerVersionChoosesTheMysqlCollationAndTheFallbackIsLoggedOncePerBuild(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = (string) $level . ' ' . strtr((string) $message, ['{version}' => (string) ($context['version'] ?? '')]);
            }
        };
        foreach ([VaultItem::class => 'creator', VaultExtensionToken::class => 'user'] as $class => $field) {
            foreach (['8.0' => 'utf8mb4_bin', '8.0.16' => 'utf8mb4_bin', '8.0.17' => 'utf8mb4_0900_bin', '8.4.3' => 'utf8mb4_0900_bin'] as $version => $collation) {
                self::assertSame($collation, $this->map(new MySQL80Platform(), [], ['serverVersion' => $version], $logger, $class)->fieldMappings[$field]->options['collation'] ?? null, $class . ' ' . $version);
            }
        }
        self::assertSame(['warning NowoVaultBundle: the user id columns (user_mapping: id) get the PAD SPACE collation utf8mb4_bin (MySQL server version "8.0"), so trailing spaces are ignored in comparisons. Use MySQL 8.0.17+ and configure the full doctrine.dbal.server_version (e.g. 8.4.3) to get utf8mb4_0900_bin.'], [$logger->records[0]]);
        self::assertCount(2, $logger->records, 'the item only, not every entity');
        self::assertStringContainsString('"8.0.16"', $logger->records[1]);

        $primary = $this->map(new MySQL80Platform(), [], ['primary' => ['serverVersion' => '8.0.16'], 'replica' => []], $logger, VaultItem::class);
        self::assertSame('utf8mb4_bin', $primary->fieldMappings['creator']->options['collation'] ?? null, 'primary / replica connection');
        self::assertCount(3, $logger->records);
    }

    public function testAnUnknownPlatformFailsLoudlyInsteadOfCachingMetadataWithoutTheCollation(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('server_version');
        $this->map(null);
    }

    public function testGranteeIdGetsTheBinaryCollationInIdMode(): void
    {
        $metadata = new ClassMetadata(VaultGrant::class);
        $metadata->setPrimaryTable(['name' => 'vault_grants']);
        $metadata->mapField(['fieldName' => 'granteeId', 'columnName' => 'grantee_id', 'type' => 'string', 'length' => 128]);

        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        (new VaultMetadataListener('i', 'f', 'g', 't', 'it', 's', 'x', User::class, VaultUserMapping::ID))
            ->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $em));

        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'], $metadata->fieldMappings['granteeId']->options);
    }

    public function testIndexesTheUserIdColumnUnlessAnIndexStartsWithIt(): void
    {
        self::assertSame(['columns' => ['user_id']], $this->map(new PostgreSQLPlatform())->table['indexes']['prefixed_extension_tokens_user_id_idx']);

        $indexed = $this->map(new PostgreSQLPlatform(), ['indexes' => ['own' => ['columns' => ['user_id', 'expires_at']]]]);
        self::assertSame(['own'], array_keys($indexed->table['indexes']));

        $unique = $this->map(new PostgreSQLPlatform(), ['uniqueConstraints' => ['own' => ['columns' => ['user_id']]]]);
        self::assertArrayNotHasKey('indexes', $unique->table);
    }

    /**
     * @param array<string, mixed> $table
     * @param array<string, mixed> $params
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function map(?AbstractPlatform $platform, array $table = [], array $params = [], ?LoggerInterface $logger = null, string $class = VaultExtensionToken::class): ClassMetadata
    {
        $field    = $class === VaultItem::class ? 'creator' : 'user';
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => 'vault_extension_tokens'] + $table);
        $metadata->mapManyToOne(['fieldName' => $field, 'targetEntity' => UserInterface::class, 'joinColumns' => [['name' => $field . '_id', 'nullable' => false]]]);

        $connection = $this->createStub(Connection::class);
        if ($platform instanceof AbstractPlatform) {
            $connection->method('getDatabasePlatform')->willReturn($platform);
        } else {
            $connection->method('getDatabasePlatform')->willThrowException(new RuntimeException('no server'));
        }
        $connection->method('getParams')->willReturn($params);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        (new VaultMetadataListener('prefixed_items', 'prefixed_folders', 'prefixed_grants', 'prefixed_tags', 'prefixed_item_tag', 'prefixed_settings', 'prefixed_extension_tokens', User::class, VaultUserMapping::ID, $logger))
            ->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $em));

        return $metadata;
    }
}

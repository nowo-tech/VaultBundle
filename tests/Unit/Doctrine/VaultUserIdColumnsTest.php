<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
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
use Nowo\VaultBundle\Tests\App\Entity\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.17.0 id mapping (LQ-V-M1, LQ-V-I1): binary collation by platform and an index on every user id column.
 */
final class VaultUserIdColumnsTest extends TestCase
{
    public function testBinaryCollationPerPlatform(): void
    {
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'], $this->map(new MySQLPlatform())->fieldMappings['user']->options);
        self::assertSame(['collation' => 'Latin1_General_100_BIN2'], $this->map(new SQLServerPlatform())->fieldMappings['user']->options);
        self::assertNull($this->map(new PostgreSQLPlatform())->fieldMappings['user']->options);
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
     *
     * @return ClassMetadata<VaultExtensionToken>
     */
    private function map(?AbstractPlatform $platform, array $table = []): ClassMetadata
    {
        $metadata = new ClassMetadata(VaultExtensionToken::class);
        $metadata->setPrimaryTable(['name' => 'vault_extension_tokens'] + $table);
        $metadata->mapManyToOne(['fieldName' => 'user', 'targetEntity' => UserInterface::class, 'joinColumns' => [['name' => 'user_id', 'nullable' => false]]]);

        $connection = $this->createStub(Connection::class);
        if ($platform instanceof AbstractPlatform) {
            $connection->method('getDatabasePlatform')->willReturn($platform);
        } else {
            $connection->method('getDatabasePlatform')->willThrowException(new RuntimeException('no server'));
        }
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        (new VaultMetadataListener('prefixed_items', 'prefixed_folders', 'prefixed_grants', 'prefixed_tags', 'prefixed_item_tag', 'prefixed_settings', 'prefixed_extension_tokens', User::class, VaultUserMapping::ID))
            ->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $em));

        return $metadata;
    }
}

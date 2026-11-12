<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultItem;
use Throwable;

use function array_chunk;
use function array_column;
use function count;
use function is_scalar;
use function is_string;
use function max;
use function preg_match;
use function sprintf;

/**
 * Raw DBAL access to the browser-extension token table while {@see VaultExtensionToken} is not
 * mapped (1.18.0, `database.conditional_mapping: true` with the browser extension off; NC-1). Rows written by an
 * earlier release (or while the extension was on) must still be exported, erased and purged: a token inside its TTL
 * would work again once the extension is re-enabled, and with the id mapping there is no foreign key cascade.
 *
 * The table is often missing (a fresh install never creates it: the schema filter hides it). Its existence is probed
 * first with a catalogue query that cannot fail (NC2-1): a failing statement would abort a surrounding PostgreSQL
 * transaction, and the host's COMMIT would then roll back the whole erasure. The probe is plain SQL, never the schema
 * manager (the schema asset filter hides the table from it).
 *
 * The SQL uses the default column names (`id`, `user_id`, `created_at`, `expires_at`, `last_used_at`) and the table name
 * as configured (`{table_prefix}_extension_tokens`, unquoted like the ORM's). With the id mapping the stored user id must
 * equal the requested id byte for byte (LQ-V-M1); with the relation mapping the id is converted with the type of the
 * host user's identifier and, for non-binary types, compared again as a string (NC2-2: no numeric coercion of `01` or
 * `1abc` to user 1). Stateless: safe in worker mode.
 */
final readonly class VaultExtensionTokenTable
{
    /** Default `{table_prefix}_extension_tokens` name. */
    public const DEFAULT_TABLE = 'vault_extension_tokens';

    public function __construct(
        private string $tableName = self::DEFAULT_TABLE,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $tableName) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid vault extension token table name "%s".', $tableName));
        }
    }

    /**
     * The user's token rows (id, created_at, expires_at, last_used_at; dates as DateTimeImmutable), oldest first.
     *
     * @return list<array{id: string, created_at: ?DateTimeImmutable, expires_at: ?DateTimeImmutable, last_used_at: ?DateTimeImmutable}>
     */
    public function rowsForUser(EntityManagerInterface $em, string $userId, bool $mapsById): array
    {
        $connection = $em->getConnection();
        if (!$this->tableExists($connection)) {
            return [];
        }

        $platform                  = $connection->getDatabasePlatform();
        [$value, $type, $asString] = $this->userValue($em, $userId, $mapsById);
        $rows                      = $connection->fetchAllAssociative(
            'SELECT id, user_id, created_at, expires_at, last_used_at FROM ' . $this->tableName . ' WHERE user_id = ? ORDER BY created_at ASC, id ASC',
            [$value],
            [$type],
        );

        $dates = Type::getType(Types::DATETIME_IMMUTABLE);
        $out   = [];
        foreach ($rows as $row) {
            $stored = $row['user_id'] ?? null;
            // LQ-V-M1: byte-exact owner with the id mapping (a case- or accent-insensitive collation matches more).
            if ($mapsById && (!is_string($stored) || $stored !== $userId)) {
                continue;
            }
            // NC2-2: the relation mapping compares the stored id as a string too (MySQL would match 1 for '01').
            if ($asString !== null && (!is_scalar($stored) || (string) $stored !== $asString)) {
                continue;
            }

            $out[] = [
                'id'           => (string) $row['id'],
                'created_at'   => $dates->convertToPHPValue($row['created_at'] ?? null, $platform),
                'expires_at'   => $dates->convertToPHPValue($row['expires_at'] ?? null, $platform),
                'last_used_at' => $dates->convertToPHPValue($row['last_used_at'] ?? null, $platform),
            ];
        }

        return $out;
    }

    /**
     * Deletes the user's token rows; returns how many (0 without the table).
     */
    public function deleteForUser(EntityManagerInterface $em, string $userId, bool $mapsById, int $batchSize = 500): int
    {
        $ids   = array_column($this->rowsForUser($em, $userId, $mapsById), 'id');
        $total = 0;
        foreach (array_chunk($ids, max(1, $batchSize)) as $chunk) {
            $connection = $em->getConnection();
            $total += (int) VaultSavepoint::write($connection, fn (): int|string => $connection->executeStatement('DELETE FROM ' . $this->tableName . ' WHERE id IN (?)', [$chunk], [ArrayParameterType::STRING]), $em);
        }

        return $total;
    }

    /**
     * Deletes the tokens expired at `$now`; returns how many (0 without the table).
     */
    public function deleteExpired(EntityManagerInterface $em, DateTimeImmutable $now): int
    {
        $connection = $em->getConnection();
        if (!$this->tableExists($connection)) {
            return 0;
        }

        return (int) VaultSavepoint::write($connection, fn (): int|string => $connection->executeStatement('DELETE FROM ' . $this->tableName . ' WHERE expires_at <= ?', [$now], [Types::DATETIME_IMMUTABLE]), $em);
    }

    /**
     * Whether the table exists in the current schema, through a catalogue query that never fails (NC2-1).
     */
    public function tableExists(Connection $connection): bool
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            // to_regclass() follows search_path and answers NULL for a missing relation.
            $platform instanceof PostgreSQLPlatform    => $connection->fetchOne('SELECT to_regclass(?)', [$this->tableName]) !== null,
            $platform instanceof SQLitePlatform        => (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$this->tableName]) > 0,
            $platform instanceof AbstractMySQLPlatform => (int) $connection->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$this->tableName]) > 0,
            $platform instanceof SQLServerPlatform     => $connection->fetchOne("SELECT OBJECT_ID(?, 'U')", [$this->tableName]) !== null,
            $platform instanceof OraclePlatform        => (int) $connection->fetchOne('SELECT COUNT(*) FROM user_tables WHERE table_name = UPPER(?)', [$this->tableName]) > 0,
            default                                    => (int) $connection->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ?', [$this->tableName]) > 0,
        };
    }

    /**
     * The user id as stored in `user_id` (value, binding type) and the string it must equal (null = no string check):
     * the id itself with the id mapping (checked byte for byte by the caller), else converted with the DBAL type of the
     * host user's identifier (e.g. a binary UUID, which is not compared as a string); the plain string when that type
     * cannot be resolved.
     *
     * @return array{0: mixed, 1: ParameterType|string, 2: ?string}
     */
    private function userValue(EntityManagerInterface $em, string $userId, bool $mapsById): array
    {
        if ($mapsById) {
            return [$userId, ParameterType::STRING, null];
        }

        try {
            $userClass = $em->getClassMetadata(VaultItem::class)->getAssociationTargetClass('creator');
            $metadata  = $em->getClassMetadata($userClass);
            $idField   = $metadata->getIdentifierFieldNames();
            $typeName  = count($idField) === 1 ? $metadata->getTypeOfField($idField[0]) : null;
            if (!is_string($typeName)) {
                return [$userId, ParameterType::STRING, $userId];
            }
            $type    = Type::getType($typeName);
            $value   = $type->convertToDatabaseValue($userId, $em->getConnection()->getDatabasePlatform());
            $binding = $type->getBindingType();
            $binary  = $binding === ParameterType::BINARY || $binding === ParameterType::LARGE_OBJECT;

            return [$value, $binding, $binary || !is_scalar($value) ? null : (string) $value];
        } catch (Throwable) {
            return [$userId, ParameterType::STRING, $userId];
        }
    }
}

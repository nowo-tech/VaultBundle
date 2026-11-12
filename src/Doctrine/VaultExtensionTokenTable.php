<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultItem;
use Throwable;

use function array_chunk;
use function array_column;
use function count;
use function is_string;
use function max;

/**
 * Raw DBAL access to the browser-extension token table while {@see VaultExtensionToken} is not
 * mapped (1.18.0, `database.conditional_mapping: true` with the browser extension off; NC-1). Rows written by an
 * earlier release (or while the extension was on) must still be exported, erased and purged: a token inside its TTL
 * would work again once the extension is re-enabled, and with the id mapping there is no foreign key cascade.
 *
 * Plain SQL on the vault entity manager's connection, never the schema manager (the schema asset filter hides the
 * table from it). A missing table counts as empty. With the id mapping the stored user id must equal the requested id
 * byte for byte (LQ-V-M1), whatever the column collation; with the relation mapping the id is converted with the type of
 * the host user's identifier. Stateless: safe in worker mode.
 */
final readonly class VaultExtensionTokenTable
{
    /** Default `{table_prefix}_extension_tokens` name. */
    public const DEFAULT_TABLE = 'vault_extension_tokens';

    public function __construct(
        private string $tableName = self::DEFAULT_TABLE,
    ) {
    }

    /**
     * The user's token rows (id, created_at, expires_at, last_used_at; dates as DateTimeImmutable), oldest first.
     *
     * @return list<array{id: string, created_at: ?DateTimeImmutable, expires_at: ?DateTimeImmutable, last_used_at: ?DateTimeImmutable}>
     */
    public function rowsForUser(EntityManagerInterface $em, string $userId, bool $mapsById): array
    {
        $connection     = $em->getConnection();
        $platform       = $connection->getDatabasePlatform();
        [$value, $type] = $this->userValue($em, $userId, $mapsById);

        try {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, user_id, created_at, expires_at, last_used_at FROM ' . $platform->quoteSingleIdentifier($this->tableName) . ' WHERE user_id = ? ORDER BY created_at ASC, id ASC',
                [$value],
                [$type],
            );
        } catch (TableNotFoundException) {
            return [];
        }

        $dates = Type::getType(Types::DATETIME_IMMUTABLE);
        $out   = [];
        foreach ($rows as $row) {
            // LQ-V-M1: byte-exact owner with the id mapping (a case- or accent-insensitive collation matches more).
            if ($mapsById && (!is_string($row['user_id'] ?? null) || $row['user_id'] !== $userId)) {
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
     * Deletes the user's token rows; returns how many.
     */
    public function deleteForUser(EntityManagerInterface $em, string $userId, bool $mapsById, int $batchSize = 500): int
    {
        $ids   = array_column($this->rowsForUser($em, $userId, $mapsById), 'id');
        $total = 0;
        foreach (array_chunk($ids, max(1, $batchSize)) as $chunk) {
            $total += (int) $em->getConnection()->executeStatement(
                'DELETE FROM ' . $em->getConnection()->getDatabasePlatform()->quoteSingleIdentifier($this->tableName) . ' WHERE id IN (?)',
                [$chunk],
                [ArrayParameterType::STRING],
            );
        }

        return $total;
    }

    /**
     * Deletes the tokens expired at `$now`; returns how many (0 without the table).
     */
    public function deleteExpired(EntityManagerInterface $em, DateTimeImmutable $now): int
    {
        try {
            return (int) $em->getConnection()->executeStatement(
                'DELETE FROM ' . $em->getConnection()->getDatabasePlatform()->quoteSingleIdentifier($this->tableName) . ' WHERE expires_at <= ?',
                [$now],
                [Types::DATETIME_IMMUTABLE],
            );
        } catch (TableNotFoundException) {
            return 0;
        }
    }

    /**
     * The user id as stored in `user_id`: the id itself with the id mapping, else converted with the DBAL type of the
     * host user's identifier (e.g. a binary UUID); the plain string when that type cannot be resolved.
     *
     * @return array{0: mixed, 1: ParameterType|string}
     */
    private function userValue(EntityManagerInterface $em, string $userId, bool $mapsById): array
    {
        if ($mapsById) {
            return [$userId, ParameterType::STRING];
        }

        try {
            $userClass = $em->getClassMetadata(VaultItem::class)->getAssociationTargetClass('creator');
            $metadata  = $em->getClassMetadata($userClass);
            $idField   = $metadata->getIdentifierFieldNames();
            $typeName  = count($idField) === 1 ? $metadata->getTypeOfField($idField[0]) : null;
            if (!is_string($typeName)) {
                return [$userId, ParameterType::STRING];
            }
            $type = Type::getType($typeName);

            return [$type->convertToDatabaseValue($userId, $em->getConnection()->getDatabasePlatform()), $type->getBindingType()];
        } catch (Throwable) {
            return [$userId, ParameterType::STRING];
        }
    }
}

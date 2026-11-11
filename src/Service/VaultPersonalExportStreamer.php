<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Exception\VaultDecryptionFailedException;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function flush;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function json_encode;
use function sodium_memzero;
use function str_replace;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Decrypted export of a user's **personal** vault (1.16.0, contract §9.3): items with `creator = user`, no owner scope
 * and not in the trash; shared-with-me and scope items are never included.
 *
 * Writes straight to the output (`echo` + `flush()`, 50 items per batch, the loaded entities detached after each batch
 * and the encoded strings wiped best effort). There is no temporary file and no in-memory buffer of the whole file.
 * A decrypt failure mid-stream stops the output, so the file is invalid by construction (JSON without its closing
 * bracket, CSV with a `#error` trailer) and logs an error with the exception class only.
 */
final readonly class VaultPersonalExportStreamer
{
    /** Items decrypted and written per batch. */
    public const BATCH_SIZE = 50;

    /** Exports up to this size are decrypted once before the first byte (the common case fails early). */
    public const PREFLIGHT_MAX_ITEMS = 500;

    /** Fixed CSV header (every field quoted; attachments are not in CSV). */
    public const CSV_COLUMNS = [
        'type', 'title', 'folder', 'tags', 'username', 'password', 'websites', 'note', 'content', 'cardholderName',
        'cardNumber', 'expiry', 'cvv', 'cardPin', 'postalCode', 'fullName', 'email', 'phone', 'addressLine1',
        'addressLine2', 'city', 'state', 'country', 'documentNumber', 'issuedBy', 'issuedDate', 'expiryDate',
    ];

    /** Estimated bytes of metadata per exported item (id, type, title, folder, tags, dates, JSON keys). */
    public const ITEM_OVERHEAD = 512;

    /** First characters a spreadsheet may evaluate as a formula (OWASP CSV injection). */
    public const CSV_FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /** Payload keys of the attachment (JSON `attachment`, never in `fields`). */
    private const ATTACHMENT_KEYS = ['attachmentName', 'attachmentContent'];

    /**
     * Stores the Doctrine registry, the cryptographer and the logger.
     */
    public function __construct(
        private ManagerRegistry $registry,
        private VaultPayloadCryptographerInterface $cryptographer,
        private ?LoggerInterface $logger = null,
        private ?string $managerName = null,
        /** export.csv_raw_values: keep CSV cells exact (no formula neutralisation; SEC-004 FQ-L3). */
        private bool $csvRawValues = false,
        private ?VaultUserReferenceResolverInterface $users = null,
    ) {
    }

    /**
     * Number of exportable items and the estimated file size, before streaming: `SUM(LENGTH(ciphertext))` (the base64
     * envelope is at least as long as the decrypted payload, attachments included, which the JSON keeps as base64) plus
     * {@see self::ITEM_OVERHEAD} bytes of metadata per item (SEC-004 FQ-I6).
     *
     * @return array{count: int, bytes: int}
     */
    public function measure(object $user): array
    {
        /** @var array{count: int|string, bytes: int|string|null} $row */
        $row = $this->em()->createQuery('SELECT COUNT(i.id) AS count, SUM(LENGTH(i.ciphertext)) AS bytes FROM ' . VaultItem::class . ' i WHERE i.creator = :user AND i.ownerScope IS NULL AND i.deletedAt IS NULL')
            ->setParameter('user', $this->users?->reference($user) ?? $user)
            ->getSingleResult();

        return ['count' => (int) $row['count'], 'bytes' => (int) $row['bytes'] + (int) $row['count'] * self::ITEM_OVERHEAD];
    }

    /**
     * Decrypts every exportable item once without writing anything.
     *
     * @throws VaultDecryptionFailedException on the first item that does not decrypt
     */
    public function preflight(object $user): void
    {
        foreach ($this->batches($user) as $items) {
            foreach ($items as $item) {
                $this->payload($item);
            }
        }
    }

    /**
     * Writes the export to the output.
     *
     * @param 'csv'|'json' $format
     */
    public function stream(object $user, string $format, bool $includeAttachments, DateTimeImmutable $exportedAt, ?string $userId): void
    {
        $csv = $format === 'csv';
        echo $csv
            ? $this->csvLine(self::CSV_COLUMNS)
            : '{"format":"nowo-vault-export","version":1,"exported_at":' . json_encode($exportedAt->format(DATE_ATOM), JSON_THROW_ON_ERROR) . ',"items":[';
        flush();

        $first = true;
        try {
            foreach ($this->batches($user) as $items) {
                foreach ($items as $item) {
                    $line = $csv ? $this->csvRow($item) : ($first ? '' : ',') . $this->jsonItem($item, $includeAttachments);
                    echo $line;
                    sodium_memzero($line);
                    $first = false;
                }
                flush();
            }
        } catch (Throwable $exception) {
            ($this->logger ?? new NullLogger())->error('Vault export stream failed.', [
                'bundle'    => 'nowo_vault',
                'action'    => 'export_stream_failed',
                'user_id'   => $userId,
                'exception' => $exception::class,
            ]);
            if ($csv) {
                echo "#error\r\n";
            }
            flush();

            return;
        }

        if (!$csv) {
            echo ']}';
        }
        flush();
    }

    /**
     * Exportable items in id batches; each batch is loaded with its folder and tags, then detached.
     *
     * @return iterable<list<VaultItem>>
     */
    private function batches(object $user): iterable
    {
        $em     = $this->em();
        $offset = 0;
        while (true) {
            /** @var list<string> $ids */
            $ids = array_column($em->createQuery('SELECT i.id AS id FROM ' . VaultItem::class . ' i WHERE i.creator = :user AND i.ownerScope IS NULL AND i.deletedAt IS NULL ORDER BY i.createdAt ASC, i.id ASC')
                ->setParameter('user', $this->users?->reference($user) ?? $user)
                ->setFirstResult($offset)
                ->setMaxResults(self::BATCH_SIZE)
                ->getArrayResult(), 'id');
            if ($ids === []) {
                return;
            }

            /** @var list<VaultItem> $items */
            $items = $em->createQuery('SELECT i, f, t FROM ' . VaultItem::class . ' i LEFT JOIN i.folder f LEFT JOIN i.tags t WHERE i.id IN (:ids) ORDER BY i.createdAt ASC, i.id ASC')
                ->setParameter('ids', $ids)
                ->getResult();
            // 1.17.0 (LQ-V-M1): never decrypt a row the user does not own, whatever the column collation matched.
            yield array_values(array_filter($items, static fn (VaultItem $item): bool => $item->getOwner()->isUser($user)));

            foreach ($items as $item) {
                foreach ($item->getTags() as $tag) {
                    $em->detach($tag);
                }
                if ($item->getFolder() !== null) {
                    $em->detach($item->getFolder());
                }
                $em->detach($item);
            }
            $offset += self::BATCH_SIZE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(VaultItem $item): array
    {
        try {
            return $this->cryptographer->decrypt($item->getCiphertext());
        } catch (Throwable) {
            throw new VaultDecryptionFailedException();
        }
    }

    private function jsonItem(VaultItem $item, bool $includeAttachments): string
    {
        $payload    = $this->payload($item);
        $attachment = null;
        if ($includeAttachments && is_string($payload['attachmentContent'] ?? null) && $payload['attachmentContent'] !== '') {
            $attachment = ['name' => is_string($payload['attachmentName'] ?? null) ? $payload['attachmentName'] : '', 'content_base64' => $payload['attachmentContent']];
        }
        foreach (self::ATTACHMENT_KEYS as $key) {
            unset($payload[$key]);
        }

        return json_encode([
            'id'         => $item->getId(),
            'type'       => $item->getItemType()->value,
            'title'      => $item->getTitle(),
            'folder'     => $item->getFolder()?->getName(),
            'tags'       => $item->getTagNames(),
            'created_at' => $item->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $item->getUpdatedAt()->format(DATE_ATOM),
            'fields'     => (object) $payload,
            'attachment' => $attachment,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function csvRow(VaultItem $item): string
    {
        $payload = $this->payload($item);
        $cells   = [];
        foreach (self::CSV_COLUMNS as $column) {
            $cells[] = match ($column) {
                'type'   => $item->getItemType()->value,
                'title'  => $item->getTitle(),
                'folder' => $item->getFolder()?->getName() ?? '',
                'tags'   => implode(', ', $item->getTagNames()),
                default  => $this->cell($payload[$column] ?? null),
            };
        }

        return $this->csvLine($this->csvRawValues ? $cells : array_map(self::neutralise(...), $cells));
    }

    /**
     * OWASP CSV-injection defence (SEC-004 FQ-L3): a cell starting with `= + - @`, tab or CR gets a leading `'`, so a
     * spreadsheet shows it as text. Write grantees can edit the owner's items, so cells are not trusted. Values that
     * legitimately start with these characters (some passwords) then start with `'`: use JSON, or
     * `export.csv_raw_values: true`, for an exact migration file.
     */
    public static function neutralise(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], self::CSV_FORMULA_PREFIXES, true) ? "'" . $cell : $cell;
    }

    private function cell(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\n", array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * One CSV line, every field quoted (`"` doubled).
     *
     * @param list<string> $cells
     */
    private function csvLine(array $cells): string
    {
        return implode(',', array_map(static fn (string $cell): string => '"' . str_replace('"', '""', $cell) . '"', $cells)) . "\r\n";
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->registry->getManager($this->managerName);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The vault export needs a Doctrine ORM entity manager.');
        }

        return $em;
    }
}

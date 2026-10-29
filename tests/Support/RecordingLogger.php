<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

use function array_map;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * In-memory PSR-3 logger for asserting log records (REQ-OBS-001).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * Stores the record.
     *
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Returns the record messages in order.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (array $record): string => $record['message'], $this->records);
    }

    /**
     * Serialises every record (messages + contexts) so tests can assert that secrets never appear.
     */
    public function dump(): string
    {
        return json_encode($this->records, JSON_THROW_ON_ERROR);
    }
}

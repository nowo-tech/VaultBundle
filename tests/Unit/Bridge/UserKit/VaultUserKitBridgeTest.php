<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Bridge\UserKit;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\VaultBundle\Bridge\UserKit\VaultUserKitEraser;
use Nowo\VaultBundle\Bridge\UserKit\VaultUserKitExportProvider;
use Nowo\VaultBundle\Dto\VaultErasureResult;
use Nowo\VaultBundle\Service\VaultPersonalDataEraserInterface;
use Nowo\VaultBundle\Service\VaultPersonalDataExporterInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 2.5.0: the UserKit eraser and export section call the vault's id ports with the string form of the user's getId().
 */
final class VaultUserKitBridgeTest extends TestCase
{
    public function testEraserPassesTheIdAndTheBatchSize(): void
    {
        $port   = new RecordingEraser();
        $eraser = new VaultUserKitEraser($port, 7);

        self::assertSame('vault', $eraser->getKey());
        self::assertTrue($eraser->supports($this->user(42)));
        $eraser->erase(new ErasureRequest($this->user(42)));
        self::assertSame([['42', 7]], $port->calls);
    }

    public function testUsersWithoutAUsableIdAreSkipped(): void
    {
        $port   = new RecordingEraser();
        $eraser = new VaultUserKitEraser($port);

        foreach ([new stdClass(), $this->user(null), $this->user(''), $this->user('  ')] as $user) {
            self::assertFalse($eraser->supports($user));
            $eraser->erase(new ErasureRequest($user));
        }
        self::assertSame([], $port->calls);
        self::assertSame(' x', VaultUserKitEraser::userId($this->user(' x')), 'The id is never trimmed.');
    }

    public function testExportSectionDropsTheUserIdKey(): void
    {
        $exporter = new class implements VaultPersonalDataExporterInterface {
            /** @var list<string> */
            public array $ids = [];

            public function export(string $userId): array
            {
                $this->ids[] = $userId;

                return ['user_id' => $userId, 'items' => [['title' => 'Mail']]];
            }
        };
        $provider = new VaultUserKitExportProvider($exporter);

        self::assertSame('vault', $provider->getSectionKey());
        self::assertTrue($provider->supports($this->user('u-1')));
        self::assertFalse($provider->supports(new stdClass()));
        self::assertSame(['items' => [['title' => 'Mail']]], $provider->export($this->user('u-1')));
        self::assertSame([], $provider->export(new stdClass()));
        self::assertSame(['u-1'], $exporter->ids);
    }

    private function user(int|string|null $id): object
    {
        return new class($id) {
            public function __construct(private readonly int|string|null $id)
            {
            }

            public function getId(): int|string|null
            {
                return $this->id;
            }
        };
    }
}

/**
 * Records the calls of the erasure port.
 */
final class RecordingEraser implements VaultPersonalDataEraserInterface
{
    /** @var list<array{0: string, 1: int}> */
    public array $calls = [];

    public function erase(string $userId, int $batchSize = 500): VaultErasureResult
    {
        $this->calls[] = [$userId, $batchSize];

        return new VaultErasureResult();
    }
}

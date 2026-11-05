<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Support;

use LogicException;
use Nowo\VaultBundle\Support\VaultAttachmentFilename;
use Nowo\VaultBundle\Support\VaultRevealedValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function random_bytes;
use function serialize;
use function str_repeat;
use function unserialize;
use function var_dump;

final class VaultRevealSupportTest extends TestCase
{
    public function testRevealedValueHidesItself(): void
    {
        $marker = 'MARK' . bin2hex(random_bytes(16));
        $value  = new VaultRevealedValue($marker);

        self::assertSame($marker, $value->get());
        self::assertSame(['value' => '***'], $value->__debugInfo());
        ob_start();
        var_dump($value);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString($marker, $dump);
        self::assertStringContainsString('***', $dump);
        self::assertStringNotContainsString($marker, print_r($value, true));
        self::assertSame(['a', 'b'], (new VaultRevealedValue(['a', 'b']))->get());

        $this->expectException(LogicException::class);
        serialize($value);
    }

    public function testRevealedValueRefusesUnserialize(): void
    {
        $this->expectException(LogicException::class);
        unserialize('O:43:"Nowo\VaultBundle\Support\VaultRevealedValue":1:{s:5:"value";s:1:"x";}');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'path traversal' => ['../../x', 'x'];
        yield 'windows path' => ['C:\\Users\\a\\scan.pdf', 'scan.pdf'];
        yield 'quote' => ['a"b.pdf', 'ab.pdf'];
        yield 'control characters' => ["scan\x07\r\n.pdf", 'scan.pdf'];
        yield '300 characters' => [str_repeat('n', 300), str_repeat('n', 100)];
        yield 'empty' => ['', 'attachment.bin'];
        yield 'dot dot' => ['..', 'attachment.bin'];
        yield 'unicode' => ['résumé.pdf', 'résumé.pdf'];
    }

    #[DataProvider('names')]
    public function testFilenameSanitiser(string $name, string $expected): void
    {
        self::assertSame($expected, VaultAttachmentFilename::sanitize($name));
    }

    public function testContentDisposition(): void
    {
        self::assertSame("attachment; filename=\"r_sum_.pdf\"; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf", VaultAttachmentFilename::contentDisposition('résumé.pdf'));
        self::assertSame("attachment; filename=\"attachment.bin\"; filename*=UTF-8''attachment.bin", VaultAttachmentFilename::contentDisposition('/'));
    }
}

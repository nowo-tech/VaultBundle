<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

/**
 * Headers of every response that carries a decrypted value: field reveal, attachment download and the decrypted
 * export (1.16.0, contract §6.6). `Cache-Control: no-store, private`, `Pragma` and `Expires` come from the bundle
 * response listener on every bundle route.
 */
final class VaultSensitiveResponseHeaders
{
    /** @var array<string, string> */
    public const HEADERS = [
        'Referrer-Policy'         => 'no-referrer',
        'X-Content-Type-Options'  => 'nosniff',
        'X-Robots-Tag'            => 'noindex, nofollow',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
    ];
}

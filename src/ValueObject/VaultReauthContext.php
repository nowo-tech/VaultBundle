<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\ValueObject;

use Nowo\VaultBundle\Internal\HiddenValues;

use function is_string;

/**
 * Framework-free view of a request that needs a fresh re-authentication (1.19.0, REQ-ARCH-001 rule 3), given to a
 * {@see \Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface} instead of the Symfony `Request`.
 *
 * Its properties hold the purpose (e.g. {@see \Nowo\VaultBundle\Security\VaultReauthProof::PURPOSE_PERSONAL_EXPORT}),
 * the user ({@see VaultUserRef}, null when the request has no user), the HTTP method and the matched route name. The
 * path ({@see path()}) and the session binding ({@see sessionBinding()}: `hash('sha256', session id)`, the value your
 * proof must carry in `sessionBinding`) are **not** properties: they live in an internal {@see \WeakMap} keyed by this
 * object, so a dump, `var_export()`, `(array)` cast, `json_encode()` or `serialize()` of the VO never shows them, and a
 * `clone` or an unserialized copy has none. Cookies, headers, the query string, the body and the session itself are
 * never copied.
 *
 * Built by the bundle for one call and never stored (FrankenPHP worker mode, kernel reset false).
 */
final readonly class VaultReauthContext
{
    /** Bag of the request values. */
    private const REQUEST = 'request';

    /**
     * @param string $purpose what the re-authentication is for
     * @param VaultUserRef|null $user the signed-in user, null when there is none
     * @param string $method HTTP method (upper case)
     * @param string|null $routeName the matched route (`_route`)
     * @param string $path path info, no query string (hidden)
     * @param string $sessionBinding `hash('sha256', session id)`, '' without a session (hidden)
     */
    public function __construct(
        public string $purpose,
        public ?VaultUserRef $user = null,
        public string $method = 'GET',
        public ?string $routeName = null,
        string $path = '',
        string $sessionBinding = '',
    ) {
        HiddenValues::put($this, [self::REQUEST => ['path' => $path, 'session' => $sessionBinding]]);
    }

    /**
     * The path info (no query string); '' on a clone or an unserialized copy.
     */
    public function path(): string
    {
        $path = HiddenValues::get($this, self::REQUEST, 'path');

        return is_string($path) ? $path : '';
    }

    /**
     * `hash('sha256', session id)` of the request ('' without a session, on a clone or an unserialized copy).
     */
    public function sessionBinding(): string
    {
        $binding = HiddenValues::get($this, self::REQUEST, 'session');

        return is_string($binding) ? $binding : '';
    }
}

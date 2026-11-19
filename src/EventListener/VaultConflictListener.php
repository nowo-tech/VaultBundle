<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\EventListener;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionResponseFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

use function in_array;
use function is_string;
use function str_starts_with;

/**
 * 1.20 (REQ-RUNTIME-003 rule 6): a lock conflict on a route of the vault answers a conflict instead of a 500.
 *
 * - Lock conflict (a deadlock, lock wait timeout or serialization failure anywhere down the chain, a
 *   {@see TransientStorageFailure}, or a lost savepoint of unknown outcome): the status of the
 *   {@see ConflictResponsePolicy} (**409** by default, 503 when the host configures it) with a {@see ConflictCode} in the
 *   `X-Conflict-Code` header:
 *   - `CONFLICT_001` with `Retry-After` when nothing of the request was committed;
 *   - `CONFLICT_002` without `Retry-After` when part of it may be: anything committed since the request started
 *     (transaction-doctrine's request mark, {@see ConflictCode::forRequest()}, when its bundle is registered with
 *     `commit_detection`; and the vault's own mark on the {@see CommitLedger} its commit-tracking middleware feeds), a
 *     committed `ConcurrencyConflict`, or a lost savepoint with no deadlock behind it.
 *
 *   The browser-extension API and the JSON actions of the manage UI answer JSON in their own shape, `{"error": "…"}`,
 *   with the code added as `"code"` (the extension's CORS headers, plus `Access-Control-Expose-Headers` for the two
 *   conflict headers). The HTML pages are rendered by the application's error page: the answer is an HttpException with
 *   a fixed, client-safe message and the storage error as previous (for the logs; no SQL or value reaches the client).
 * - A unique key refusing an item save (two requests creating the same new tag name for the same owner at once): 409
 *   with the domain code {@see self::DUPLICATE_KEY} in `X-Error-Code`, never a `CONFLICT_*` code (it is not a lock
 *   conflict). Nothing was saved, and saving again reuses the tag the other request created.
 * - Anything else (a unique violation elsewhere, a lost connection, any other storage error) is not mapped: a real error.
 *
 * Only the vault's controllers: the host's routes, and the host's calls of the vault services, keep the host's error
 * handling. Stateless apart from the shared ledger, a monotonic counter read by difference only (FrankenPHP worker
 * mode, kernel reset off).
 */
final readonly class VaultConflictListener
{
    /** kernel.exception priority: after the application's own listeners, before the framework's error listener (-128). */
    public const PRIORITY = -64;

    /** kernel.request priority: before the router (32) and the firewall (8), so no controller wrote yet. */
    public const REQUEST_PRIORITY = 2048;

    /** Request attribute holding the vault's own commit mark. */
    public const MARK_ATTRIBUTE = '_nowo_vault_commit_mark';

    /** Response header carrying a domain error code (never a conflict code). */
    public const ERROR_CODE_HEADER = 'X-Error-Code';

    /** Domain code of an item save refused by a unique key (a tag created at the same time by another request). */
    public const DUPLICATE_KEY = 'VAULT_DUPLICATE_KEY';

    /** Message of a CONFLICT_001 answer (nothing committed). */
    public const RETRY_SAFE_MESSAGE = 'Another change is being saved at the same time. Retry in a moment.';

    /** Message of a CONFLICT_002 answer (part of the request may be committed). */
    public const COMMITTED_MESSAGE = 'Part of the change may already be saved. Reload the page before trying again.';

    /** Message of the duplicate-key answer. */
    public const DUPLICATE_KEY_MESSAGE = 'A tag with this name was created at the same time. Nothing was saved: submit the form again.';

    /** Controller namespace of the bundle. */
    private const CONTROLLER_PREFIX = 'Nowo\\VaultBundle\\Controller\\';

    /** The browser-extension API (JSON with the extension's CORS headers). */
    private const EXTENSION_CONTROLLER = self::CONTROLLER_PREFIX . 'VaultBrowserExtensionController';

    /** JSON actions of the manage UI. */
    private const JSON_ACTIONS = [
        self::CONTROLLER_PREFIX . 'VaultManageController::revealItemField',
        self::CONTROLLER_PREFIX . 'VaultManageController::generatePassword',
        self::CONTROLLER_PREFIX . 'VaultRevealController::attachment',
    ];

    /** Item saves that may create a tag (the only bundle routes whose writes meet a unique key under concurrency). */
    private const TAG_WRITING_ACTIONS = [
        self::CONTROLLER_PREFIX . 'VaultManageController::newItem',
        self::CONTROLLER_PREFIX . 'VaultManageController::editItem',
        self::CONTROLLER_PREFIX . 'VaultScopeController::newItem',
    ];

    private ConflictResponsePolicy $policy;

    private TransientFailureClassifier $classifier;

    /**
     * @param ConflictResponsePolicy|null $policy the conflict status and default Retry-After (null = 409, 1 s)
     * @param CommitLedger|null $ledger real commits counted by the commit-tracking middleware (null = none)
     * @param VaultBrowserExtensionResponseFactory|null $extensionResponses CORS of the browser-extension API
     */
    public function __construct(
        ?ConflictResponsePolicy $policy = null,
        private ?CommitLedger $ledger = null,
        private ?RequestStack $requestStack = null,
        private ?VaultBrowserExtensionResponseFactory $extensionResponses = null,
    ) {
        $this->policy     = $policy ?? new ConflictResponsePolicy();
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Takes the vault's commit mark on the main request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($this->ledger instanceof CommitLedger && $event->isMainRequest()) {
            // @igor-ignore - Per-request Request / Response objects; not shared worker state.
            $event->getRequest()->attributes->set(self::MARK_ATTRIBUTE, new CommitMark($this->ledger));
        }
    }

    /**
     * Maps a lock conflict (any vault route) or a duplicate tag (item saves) to its answer.
     */
    public function onException(ExceptionEvent $event): void
    {
        $request    = $event->getRequest();
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || !str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            return;
        }

        $failure = $event->getThrowable();
        if ($this->isLockConflict($failure)) {
            $code    = $this->code($request, $failure);
            $message = $code->retrySafe() ? self::RETRY_SAFE_MESSAGE : self::COMMITTED_MESSAGE;
            $hint    = $this->hint($failure);

            if (str_starts_with($controller, self::EXTENSION_CONTROLLER . '::') || in_array($controller, self::JSON_ACTIONS, true)) {
                $event->setResponse($this->json($request, $controller, $code, $message, $hint));

                return;
            }

            $event->setThrowable($this->policy->httpExceptionFor($code, $message, $failure, $hint));

            return;
        }

        if (in_array($controller, self::TAG_WRITING_ACTIONS, true) && $this->isUniqueViolation($failure)) {
            $event->setThrowable(new ConflictHttpException(self::DUPLICATE_KEY_MESSAGE, $failure, 0, [self::ERROR_CODE_HEADER => self::DUPLICATE_KEY]));
        }
    }

    /**
     * The JSON conflict answer: the existing `{"error": "…"}` shape plus `"code"`.
     */
    private function json(Request $request, string $controller, ConflictCode $code, string $message, ?int $hint): JsonResponse
    {
        $body = ['error' => $message, 'code' => $code->value];
        if ($this->extensionResponses instanceof VaultBrowserExtensionResponseFactory && str_starts_with($controller, self::EXTENSION_CONTROLLER . '::')) {
            $response = $this->extensionResponses->json($body, $this->policy->status, $request);
            if ($response->headers->has('Access-Control-Allow-Origin')) {
                // @igor-ignore - Per-request Request / Response objects; not shared worker state.
                $response->headers->set('Access-Control-Expose-Headers', ConflictResponsePolicy::CODE_HEADER . ', Retry-After');
            }
        } else {
            $response = new JsonResponse($body, $this->policy->status);
        }

        // @igor-ignore - Per-request Request / Response objects; not shared worker state.
        $response->headers->add($this->policy->headersFor($code, $hint));

        return $response;
    }

    private function isLockConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return $this->classifier->isTransient($failure) || $this->classifier->isCommitOutcomeUnknown($failure);
    }

    /**
     * CONFLICT_002 when transaction-doctrine's request mark, or the vault's own one, saw a commit; else the failure decides.
     */
    private function code(Request $request, Throwable $failure): ConflictCode
    {
        $code = ConflictCode::forRequest($request, $failure);
        if ($code->retrySafe() && $this->ownMark($request)?->committedSince() === true) {
            return ConflictCode::Committed;
        }

        return $code;
    }

    private function ownMark(Request $request): ?CommitMark
    {
        $mark = $request->attributes->get(self::MARK_ATTRIBUTE) ?? $this->requestStack?->getMainRequest()?->attributes->get(self::MARK_ATTRIBUTE);

        return $mark instanceof CommitMark ? $mark : null;
    }

    /** The first Retry-After hint of the chain (a transient failure of a runner); null for none or zero. */
    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint !== null && $hint > 0 ? $hint : null;
            }
        }

        return null;
    }

    private function isUniqueViolation(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }
}

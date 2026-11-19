<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionResponseFactory;
use Nowo\VaultBundle\EventListener\VaultConflictListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function json_decode;

#[CoversClass(VaultConflictListener::class)]
final class VaultConflictListenerTest extends TestCase
{
    private const EDIT = 'Nowo\\VaultBundle\\Controller\\VaultManageController::editItem';

    private const TRASH = 'Nowo\\VaultBundle\\Controller\\VaultManageController::trashItem';

    private const LOGINS = 'Nowo\\VaultBundle\\Controller\\VaultBrowserExtensionController::logins';

    private const REVEAL = 'Nowo\\VaultBundle\\Controller\\VaultManageController::revealItemField';

    /**
     * @return iterable<string, array{0: Throwable}>
     */
    public static function retrySafeConflicts(): iterable
    {
        yield 'deadlock (MySQL 1213)' => [new DeadlockException(self::driverError('Deadlock found', '40001', 1213), null)];
        yield 'lock wait timeout (MySQL 1205)' => [new LockWaitTimeoutException(self::driverError('Lock wait timeout', 'HY000', 1205), null)];
        yield 'serialization failure (PostgreSQL 40001)' => [new DriverException(self::driverError('could not serialize', '40001'), null)];
        yield 'deadlock (PostgreSQL 40P01)' => [new DriverException(self::driverError('deadlock detected', '40P01'), null)];
        yield 'lock timeout (PostgreSQL 55P03)' => [new DriverException(self::driverError('lock timeout', '55P03'), null)];
        yield 'MariaDB 1020' => [new DriverException(self::driverError('Record has changed', 'HY000', 1020), null)];
        yield 'the runner\'s TransientStorageFailure' => [TransientStorageFailure::fromPrevious(new RuntimeException('x'), 1)];
        yield 'wrapped by the kernel' => [new RuntimeException('wrapped', 0, new DeadlockException(self::driverError('Deadlock', '40001', 1213), null))];
    }

    #[DataProvider('retrySafeConflicts')]
    public function testALockConflictWithNothingCommittedAnswers409Conflict001WithRetryAfter(Throwable $failure): void
    {
        $answer = $this->answer(new VaultConflictListener(), $failure);

        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(VaultConflictListener::RETRY_SAFE_MESSAGE, $answer->getMessage());
        self::assertSame($failure, $answer->getPrevious(), 'the storage error stays for the logs');
    }

    public function testTheRunnerHintAndTheHostPolicyAreUsed(): void
    {
        $listener = new VaultConflictListener(new ConflictResponsePolicy(503, 7));

        $hinted = $this->answer($listener, new RuntimeException('w', 0, TransientStorageFailure::fromPrevious(new RuntimeException('x'), 4)));
        self::assertSame(503, $hinted->getStatusCode());
        self::assertSame('4', $hinted->getHeaders()['Retry-After']);

        self::assertSame('7', $this->answer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x'), 0))->getHeaders()['Retry-After'], 'a zero hint is no hint');
        self::assertSame('7', $this->answer($listener, new DeadlockException(self::driverError('Deadlock', '40001', 1213), null))->getHeaders()['Retry-After']);
    }

    public function testACommitEarlierInTheRequestAnswersConflict002WithoutRetryAfter(): void
    {
        $ledger   = new CommitLedger();
        $listener = new VaultConflictListener(null, $ledger);
        $request  = $this->request();
        $listener->onRequest($this->requestEvent($request, HttpKernelInterface::MAIN_REQUEST));
        self::assertInstanceOf(CommitMark::class, $request->attributes->get(VaultConflictListener::MARK_ATTRIBUTE));

        $failure = TransientStorageFailure::fromPrevious(new RuntimeException('x'), 1);
        self::assertSame(ConflictCode::RETRY_SAFE, $this->answer($listener, $failure, $request)->getHeaders()['X-Conflict-Code'], 'nothing committed yet');

        $ledger->record();
        $answer = $this->answer($listener, $failure, $request);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders());
        self::assertSame(VaultConflictListener::COMMITTED_MESSAGE, $answer->getMessage());
    }

    public function testASubRequestReadsTheMarkOfTheMainRequest(): void
    {
        $ledger   = new CommitLedger();
        $stack    = new RequestStack();
        $listener = new VaultConflictListener(null, $ledger, $stack);
        $main     = $this->request();
        $stack->push($main);
        $listener->onRequest($this->requestEvent($main, HttpKernelInterface::MAIN_REQUEST));
        $sub = $this->request();
        $listener->onRequest($this->requestEvent($sub, HttpKernelInterface::SUB_REQUEST));
        self::assertNull($sub->attributes->get(VaultConflictListener::MARK_ATTRIBUTE));

        $ledger->record();

        self::assertSame(ConflictCode::COMMITTED, $this->answer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x')), $sub)->getHeaders()['X-Conflict-Code']);
    }

    public function testWithoutALedgerNoMarkIsTaken(): void
    {
        $request = $this->request();
        (new VaultConflictListener())->onRequest($this->requestEvent($request, HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($request->attributes->has(VaultConflictListener::MARK_ATTRIBUTE));
    }

    public function testTransactionDoctrineRequestMarkAndCommittedConflictsAnswerConflict002(): void
    {
        $listener = new VaultConflictListener();
        $ledger   = new CommitLedger();
        $request  = $this->request();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x')), $request)->getHeaders(), 'host commit earlier in the request');

        $committed = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, new RuntimeException('x'), true);
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, $committed)->getHeaders());

        $lostSavepoint = new DriverException(self::driverError('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null);
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, $lostSavepoint)->getHeaders(), 'commit outcome unknown');
    }

    public function testTheExtensionApiAnswersItsJsonShapeWithTheCodeAndExposesTheHeaders(): void
    {
        $listener = new VaultConflictListener(null, null, null, new VaultBrowserExtensionResponseFactory([], 'prod'));
        $request  = $this->request(self::LOGINS);
        $request->headers->set('Origin', 'chrome-extension://abcdef');

        $response = $this->jsonAnswer($listener, new DeadlockException(self::driverError('Deadlock', '40001', 1213), null), $request);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => VaultConflictListener::RETRY_SAFE_MESSAGE, 'code' => ConflictCode::RETRY_SAFE], json_decode((string) $response->getContent(), true));
        self::assertSame(ConflictCode::RETRY_SAFE, $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('chrome-extension://abcdef', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('X-Conflict-Code, Retry-After', $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function testTheExtensionApiWithoutAnAllowedOriginSendsNoCorsHeaders(): void
    {
        $listener = new VaultConflictListener(new ConflictResponsePolicy(503, 2), null, null, new VaultBrowserExtensionResponseFactory(['https://only.example'], 'prod'));
        $request  = $this->request(self::LOGINS);
        $request->headers->set('Origin', 'https://other.example');
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger = new CommitLedger()));
        $ledger->record();

        $response = $this->jsonAnswer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x')), $request);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => VaultConflictListener::COMMITTED_MESSAGE, 'code' => ConflictCode::COMMITTED], json_decode((string) $response->getContent(), true));
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        self::assertFalse($response->headers->has('Access-Control-Expose-Headers'));
    }

    public function testJsonActionsOfTheManageUiAnswerJson(): void
    {
        foreach ([self::REVEAL, self::LOGINS] as $controller) {
            $response = $this->jsonAnswer(new VaultConflictListener(), new DeadlockException(self::driverError('Deadlock', '40001', 1213), null), $this->request($controller));

            self::assertSame(['error' => VaultConflictListener::RETRY_SAFE_MESSAGE, 'code' => ConflictCode::RETRY_SAFE], json_decode((string) $response->getContent(), true));
            self::assertSame(ConflictCode::RETRY_SAFE, $response->headers->get('X-Conflict-Code'));
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function tagWritingActions(): iterable
    {
        yield 'edit' => [self::EDIT];
        yield 'new item' => ['Nowo\\VaultBundle\\Controller\\VaultManageController::newItem'];
        yield 'new scope item' => ['Nowo\\VaultBundle\\Controller\\VaultScopeController::newItem'];
    }

    #[DataProvider('tagWritingActions')]
    public function testAUniqueKeyOnAnItemSaveAnswersTheDomain409WithoutAConflictCode(string $controller): void
    {
        $event = $this->event(new RuntimeException('w', 0, new UniqueConstraintViolationException(self::driverError('Duplicate entry', '23000', 1062), null)), $this->request($controller));
        (new VaultConflictListener())->onException($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(ConflictHttpException::class, $answer);
        self::assertSame([VaultConflictListener::ERROR_CODE_HEADER => VaultConflictListener::DUPLICATE_KEY], $answer->getHeaders());
        self::assertSame(VaultConflictListener::DUPLICATE_KEY_MESSAGE, $answer->getMessage());
    }

    public function testAUniqueViolationOnAnotherRouteIsNotMapped(): void
    {
        $failure = new UniqueConstraintViolationException(self::driverError('Duplicate entry', '23000', 1062), null);
        $event   = $this->event($failure, $this->request(self::TRASH));
        (new VaultConflictListener())->onException($event);

        self::assertSame($failure, $event->getThrowable());
    }

    /**
     * @return iterable<string, array{0: Throwable}>
     */
    public static function notConflicts(): iterable
    {
        yield 'lost connection' => [new ConnectionLost(self::driverError('MySQL server has gone away', '08S01', 2006), null)];
        yield 'other storage error' => [new DriverException(self::driverError('syntax error', '42000', 1064), null)];
        yield 'domain error' => [new RuntimeException('nope', 1213)];
    }

    #[DataProvider('notConflicts')]
    public function testOtherFailuresAreNotMapped(Throwable $failure): void
    {
        $event = $this->event($failure);
        (new VaultConflictListener())->onException($event);

        self::assertSame($failure, $event->getThrowable());
        self::assertNull($event->getResponse());
    }

    public function testHostRoutesKeepTheHostErrorHandling(): void
    {
        $failure = new DeadlockException(self::driverError('Deadlock', '40001', 1213), null);
        foreach (['App\\Controller\\HomeController::index', null, ['not', 'a string']] as $controller) {
            $request = Request::create('/');
            $request->attributes->set('_controller', $controller);
            $event = $this->event($failure, $request);
            (new VaultConflictListener())->onException($event);

            self::assertSame($failure, $event->getThrowable());
        }
    }

    private static function driverError(string $message, ?string $sqlState, int $code = 0): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }

    private function request(string $controller = self::EDIT): Request
    {
        $request = Request::create('/tools/vault/items/1/edit', 'POST');
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    private function requestEvent(Request $request, int $type): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    private function event(Throwable $failure, ?Request $request = null): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request ?? $this->request(), HttpKernelInterface::MAIN_REQUEST, $failure);
    }

    private function answer(VaultConflictListener $listener, Throwable $failure, ?Request $request = null): HttpException
    {
        $event = $this->event($failure, $request);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);

        return $answer;
    }

    private function jsonAnswer(VaultConflictListener $listener, Throwable $failure, Request $request): JsonResponse
    {
        $event = $this->event($failure, $request);
        $listener->onException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }
}

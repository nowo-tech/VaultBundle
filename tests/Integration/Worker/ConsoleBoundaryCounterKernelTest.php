<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Worker;

use Closure;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\EventListener\VaultUnitOfWorkSubscriber;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\Tests\Integration\Ports\VariantKernelSupport;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;

use const PHP_INT_MIN;

/**
 * Audit NM (B6): the console boundary of the private entity manager survives listeners that throw or stop the
 * propagation. Real Symfony Console runs in-process, in one kernel kept alive (no services resetter), with HTTP
 * requests in between.
 */
final class ConsoleBoundaryCounterKernelTest extends KernelTestCase
{
    use VariantKernelSupport;

    private ConsoleProbe $probe;

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    #[Test]
    public function aThrowingTerminateListenerDoesNotStopLaterCommandsFromClearing(): void
    {
        [$application, $dispatcher] = $this->application();
        $dispatcher->addListener(ConsoleEvents::TERMINATE, static function (): never {
            throw new RuntimeException('terminate listener');
        }, 0);

        $this->runConsole($application, 'app:touch');
        $this->assertNextOutermostCommandClears($application);
        $this->assertNextMainRequestClears();
    }

    #[Test]
    public function aThrowingErrorListenerDoesNotLeaveTheDepthBehind(): void
    {
        [$application, $dispatcher] = $this->application();
        $dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new RuntimeException('error listener');
        }, 0);

        $this->runConsole($application, 'app:fail');
        $this->assertNextOutermostCommandClears($application);
    }

    #[Test]
    public function anUnknownNestedCommandKeepsTheOuterUnit(): void
    {
        [$application] = $this->application();
        $application->addCommand(new class('app:outer-unknown', $this->probe) extends Command {
            public function __construct(string $name, private readonly ConsoleProbe $probe)
            {
                parent::__construct($name);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->probe->manager->live();
                try {
                    $this->getApplication()?->doRun(new ArrayInput(['command' => 'app:does-not-exist']), $output);
                } catch (CommandNotFoundException) {
                    // The unknown nested command fails; the outer one goes on.
                }
                $this->probe->kept = $this->probe->manager->isCreated();

                return Command::SUCCESS;
            }
        });

        $this->runConsole($application, 'app:outer-unknown');
        self::assertTrue($this->probe->kept, 'the outer unit was not discarded mid-run');
    }

    #[Test]
    public function aNestedCommandWhoseStartEventIsStoppedKeepsTheOuterUnit(): void
    {
        [$application, $dispatcher] = $this->application();
        $dispatcher->addListener(ConsoleEvents::COMMAND, static function (ConsoleCommandEvent $event): void {
            if ($event->getCommand()?->getName() === 'app:noop') {
                $event->stopPropagation();
            }
        }, 5000);
        $application->addCommand(new class('app:outer-nested', $this->probe) extends Command {
            public function __construct(string $name, private readonly ConsoleProbe $probe)
            {
                parent::__construct($name);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->probe->manager->live();
                $this->getApplication()?->doRun(new ArrayInput(['command' => 'app:noop']), $output);
                $this->probe->kept = $this->probe->manager->isCreated();

                return Command::SUCCESS;
            }
        });

        $this->runConsole($application, 'app:outer-nested');
        self::assertTrue($this->probe->kept, 'the nested terminate did not clear the outer unit');
        $this->assertNextOutermostCommandClears($application);
    }

    #[Test]
    public function aSelfRecursiveCommandWithAThrowingErrorListenerKeepsTheOuterRunAndHeals(): void
    {
        [$application, $dispatcher] = $this->application();
        $throw                      = static function (): never {
            throw new RuntimeException('error listener');
        };
        $application->addCommand(new class('app:rec', $this->probe, $dispatcher, $throw) extends Command {
            private int $depth = 0;

            public function __construct(string $name, private readonly ConsoleProbe $probe, private readonly EventDispatcherInterface $dispatcher, private readonly Closure $throw)
            {
                parent::__construct($name);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                if (++$this->depth > 1) {
                    throw new RuntimeException('inner run failed');
                }
                $this->probe->manager->live();
                $this->dispatcher->addListener(ConsoleEvents::ERROR, $this->throw);
                try {
                    $this->getApplication()?->doRun(new ArrayInput(['command' => 'app:rec']), $output);
                } catch (RuntimeException) {
                    // The throwing error listener escapes the inner run.
                } finally {
                    $this->dispatcher->removeListener(ConsoleEvents::ERROR, $this->throw);
                }
                $this->probe->kept = $this->probe->manager->isCreated();

                return Command::SUCCESS;
            }
        });

        $this->runConsole($application, 'app:rec');
        self::assertTrue($this->probe->kept, 'the failing inner run of the same command did not end the outer unit');
        $this->assertNextOutermostCommandClears($application);
    }

    #[Test]
    public function anErrorListenerThrowingAtTheLowestPriorityDoesNotLeaveAFrameBehind(): void
    {
        [$application, $dispatcher] = $this->application();
        $dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new RuntimeException('late error listener');
        }, PHP_INT_MIN);

        $this->runConsole($application, 'app:fail');
        $this->assertNextOutermostCommandClears($application);
        $this->assertNextMainRequestClears();
        $this->assertNextOutermostCommandClears($application);
    }

    /**
     * Audit NM revision 3: a message handler runs a command that fails with a throwing console.error listener, the
     * message fails and the worker stops: the stale frame's saved "message in flight" must not survive the stop.
     */
    #[Test]
    public function aFailedCommandInsideAFailedMessageDoesNotLeaveTheMessageFlagBehind(): void
    {
        [$application, $dispatcher] = $this->application();
        $throw                      = static function (): never {
            throw new RuntimeException('error listener');
        };

        $dispatcher->dispatch(new stdClass(), VaultUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_RECEIVED);
        $dispatcher->addListener(ConsoleEvents::ERROR, $throw);
        $this->runConsole($application, 'app:fail');
        $dispatcher->removeListener(ConsoleEvents::ERROR, $throw);
        $dispatcher->dispatch(new stdClass(), VaultUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_FAILED);
        $dispatcher->dispatch(new stdClass(), VaultUnitOfWorkSubscriber::MESSENGER_WORKER_STOPPED);

        $this->assertNextMainRequestClears();
        $this->assertNextOutermostCommandClears($application);
    }

    /**
     * A main request through the kept-alive kernel (no services resetter) drops the private manager, at its start
     * and at kernel.terminate.
     */
    private function assertNextMainRequestClears(): void
    {
        $this->probe->manager->live();
        self::assertTrue($this->probe->manager->isCreated());
        $httpKernel = self::getContainer()->get('http_kernel');
        assert($httpKernel instanceof HttpKernelInterface);
        $request  = Request::create('/tools/vault/items');
        $response = $httpKernel->handle($request);
        if ($httpKernel instanceof TerminableInterface) {
            $httpKernel->terminate($request, $response);
        }
        self::assertFalse($this->probe->manager->isCreated(), 'the main request cleared the private manager');
    }

    private function assertNextOutermostCommandClears(Application $application): void
    {
        $this->probe->manager->live();
        self::assertTrue($this->probe->manager->isCreated());
        $this->runConsole($application, 'app:noop');
        self::assertFalse($this->probe->manager->isCreated(), 'the outermost command cleared the private manager');
    }

    /**
     * @return array{Application, EventDispatcherInterface}
     */
    private function application(): array
    {
        self::ensureKernelShutdown();
        $kernel    = self::bootKernel(['environment' => 'private_relation_new_worker', 'debug' => false]);
        $container = self::getContainer();
        self::resetSchema($container);
        $manager = self::vaultManager($container);
        assert($manager instanceof VaultEntityManager);
        $this->probe = new ConsoleProbe($manager);
        $dispatcher  = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $application->addCommand(new class('app:noop') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return Command::SUCCESS;
            }
        });
        $application->addCommand(new class('app:touch', $this->probe) extends Command {
            public function __construct(string $name, private readonly ConsoleProbe $probe)
            {
                parent::__construct($name);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->probe->manager->live();

                return Command::SUCCESS;
            }
        });
        $application->addCommand(new class('app:fail') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new RuntimeException('command failure');
            }
        });

        return [$application, $dispatcher];
    }

    private function runConsole(Application $application, string $command): void
    {
        try {
            $application->run(new ArrayInput(['command' => $command]), new BufferedOutput());
        } catch (RuntimeException) {
            // A throwing listener escapes the run: the next command must still behave.
        }
    }
}

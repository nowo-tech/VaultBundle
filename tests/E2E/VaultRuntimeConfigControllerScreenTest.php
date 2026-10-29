<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use LogicException;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Controller\VaultRuntimeConfigController;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Tests\App\ConfigStorageKernel;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Encryption key modes of the runtime config screen (no YAML key, with / without encryption at rest) rendered with the
 * real Twig / Form / router services of the config-storage kernel.
 */
final class VaultRuntimeConfigControllerScreenTest extends KernelTestCase
{
    private Session $session;

    private ?VaultSettings $stored = null;

    protected static function getKernelClass(): string
    {
        return ConfigStorageKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testEditableModeThenGeneratedKeyIsShownOnce(): void
    {
        $controller = $this->controller(storageAvailable: true);

        $page = (string) $controller($this->request('GET'))->getContent();
        self::assertStringContainsString('name="encryption_key"', $page);

        $response = $controller($this->request('POST', ['generate_encryption_key' => '1']));
        self::assertTrue($response->isRedirect('/tools/vault/runtime-config'));
        self::assertNotNull($this->stored?->getEncryptionKey());
        $key = $this->stored->getEncryptionKey();

        // One-time display, then gone.
        self::assertStringContainsString($key, (string) $controller($this->request('GET'))->getContent());
        self::assertStringNotContainsString($key, (string) $controller($this->request('GET'))->getContent());
    }

    public function testUnavailableModeWithoutEncryptionAtRest(): void
    {
        $controller = $this->controller(storageAvailable: false);

        $page = (string) $controller($this->request('GET'))->getContent();

        self::assertStringNotContainsString('name="encryption_key"', $page);
        self::assertStringContainsString('doctrine-encrypt-bundle', $page);
    }

    public function testBlankOneTimeKeyIsIgnoredAndTemplateFallsBack(): void
    {
        $this->session->set('vault.encryption_key.one_time', '  ');
        $controller = $this->controller(storageAvailable: true, overrides: ['templates' => ['runtime_config' => '']]);

        $page = (string) $controller($this->request('GET'))->getContent();

        self::assertStringContainsString('name="encryption_key"', $page);
        self::assertFalse($this->session->has('vault.encryption_key.one_time'));
    }

    public function testMissingRouteNameIsAConfigurationError(): void
    {
        $controller = $this->controller(storageAvailable: true, overrides: ['routes' => ['runtime_config' => ['name' => '']]]);

        $this->expectException(LogicException::class);
        $controller($this->request('GET'));
    }

    public function testDisabledStorageIsNotFound(): void
    {
        $controller = $this->controller(storageAvailable: true, databaseEnabled: false);

        $this->expectException(NotFoundHttpException::class);
        $controller($this->request('GET'));
    }

    public function testNonAdminIsDenied(): void
    {
        $controller = $this->controller(storageAvailable: true, allowUnauthenticated: false);

        $this->expectException(AccessDeniedException::class);
        $controller($this->request('GET'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function controller(bool $storageAvailable, bool $databaseEnabled = true, bool $allowUnauthenticated = true, array $overrides = []): VaultRuntimeConfigController
    {
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->method('findByScope')->willReturnCallback(fn (): ?VaultSettings => $this->stored);
        $settings->method('save')->willReturnCallback(function (VaultSettings $entity): void {
            $this->stored = $entity;
        });

        $baseline   = VaultRuntimeConfigFactory::baseline($overrides + ['encryption_key' => '']);
        $provider   = new VaultRuntimeConfigProvider($baseline, true, $settings, new ArrayAdapter());
        $translator = self::getContainer()->get(TranslatorInterface::class);

        $controller = new VaultRuntimeConfigController(
            $provider,
            new VaultRuntimeConfigWriter(true, $settings, $provider, $storageAvailable),
            $settings,
            $translator,
            new VaultRuntimeConfigResolver($provider, $baseline, true),
            $databaseEnabled,
            $allowUnauthenticated,
        );

        $real = self::getContainer()->get(VaultRuntimeConfigController::class);
        $controller->setContainer((new ReflectionProperty(AbstractController::class, 'container'))->getValue($real));

        return $controller;
    }

    /**
     * @param array<string, string> $body
     */
    private function request(string $method, array $body = []): Request
    {
        $request = Request::create('/tools/vault/runtime-config', $method, $body);
        $request->setSession($this->session);
        $stack = self::getContainer()->get('request_stack');
        while ($stack->pop() !== null) {
        }
        $stack->push($request);

        if ($method === 'POST') {
            /** @var CsrfTokenManagerInterface $csrf */
            $csrf = self::getContainer()->get('security.csrf.token_manager');
            $request->request->set('_token', $csrf->getToken('vault_runtime_config')->getValue());
        }

        return $request;
    }
}

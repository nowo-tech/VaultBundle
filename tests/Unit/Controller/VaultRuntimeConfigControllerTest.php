<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Controller;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Controller\VaultRuntimeConfigController;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class VaultRuntimeConfigControllerTest extends TestCase
{
    /**
     * A YAML key may already encrypt items: generating a DB key would silently switch the master key.
     */
    public function testGenerateKeyIsBlockedWhenYamlKeyIsConfigured(): void
    {
        $settings = $this->settingsRepository(null);
        $settings->expects(self::never())->method('save');

        $session = $this->post($settings, SodiumVaultPayloadCryptographer::generateKeyBase64(), ['generate_encryption_key' => '1']);

        self::assertSame(['vault.flash.runtime_config.key_exists_yaml'], $session->getFlashBag()->get('info'));
    }

    public function testPastedKeyIsIgnoredWhenYamlKeyIsConfigured(): void
    {
        $settings = $this->settingsRepository(null);
        $settings->expects(self::once())->method('save')->with(self::callback(
            static fn (VaultSettings $entity): bool => $entity->getEncryptionKey() === null,
        ));

        $this->post($settings, SodiumVaultPayloadCryptographer::generateKeyBase64(), [
            'encryption_key'       => SodiumVaultPayloadCryptographer::generateKeyBase64(),
            'max_attachment_bytes' => '1000',
        ]);
    }

    public function testGenerateKeyIsBlockedWhenDatabaseKeyExists(): void
    {
        $settings = $this->settingsRepository(new VaultSettings(encryptionKey: SodiumVaultPayloadCryptographer::generateKeyBase64()));
        $settings->expects(self::never())->method('save');

        $session = $this->post($settings, '', ['generate_encryption_key' => '1']);

        self::assertSame(['vault.flash.runtime_config.key_exists'], $session->getFlashBag()->get('info'));
    }

    public function testInvalidPastedKeyIsRejected(): void
    {
        $settings = $this->settingsRepository(null);
        $settings->expects(self::never())->method('save');

        $session = $this->post($settings, '', ['encryption_key' => 'not-a-key', 'max_attachment_bytes' => '1000']);

        self::assertSame(['vault.flash.runtime_config.key_invalid'], $session->getFlashBag()->get('error'));
    }

    public function testKeyStorageIsRefusedWithoutEncryptionAtRest(): void
    {
        foreach ([
            ['generate_encryption_key' => '1'],
            ['encryption_key' => SodiumVaultPayloadCryptographer::generateKeyBase64(), 'max_attachment_bytes' => '1000'],
        ] as $body) {
            $settings = $this->settingsRepository(null);
            $settings->expects(self::never())->method('save');

            $session = $this->post($settings, '', $body, storageAvailable: false);

            self::assertSame(['vault.flash.runtime_config.key_storage_unavailable'], $session->getFlashBag()->get('error'));
        }
    }

    public function testValidPastedKeyIsStoredWhenNoKeyExists(): void
    {
        $key      = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $settings = $this->settingsRepository(null);
        $settings->expects(self::once())->method('save')->with(self::callback(
            static fn (VaultSettings $entity): bool => $entity->getEncryptionKey() === $key,
        ));

        $session = $this->post($settings, '', ['encryption_key' => $key, 'max_attachment_bytes' => '1000']);

        self::assertSame(['vault.flash.runtime_config.key_stored'], $session->getFlashBag()->get('success'));
    }

    /**
     * @param array<string, string> $body
     */
    private function post(
        VaultSettingsRepositoryInterface $settings,
        string $yamlKey,
        array $body,
        bool $storageAvailable = true,
    ): Session {
        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => $yamlKey]);
        $provider = new VaultRuntimeConfigProvider($baseline, true, $settings, new ArrayAdapter());

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new VaultRuntimeConfigController(
            $provider,
            new VaultRuntimeConfigWriter(true, $settings, $provider, $storageAvailable),
            $settings,
            $translator,
            new VaultRuntimeConfigResolver($provider, $baseline, true),
            true,
            true,
        );

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/tools/vault/runtime-config', 'POST', $body + ['_token' => 'token']);
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/tools/vault/runtime-config');

        $container = new Container();
        $container->set('request_stack', $requestStack);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $controller->setContainer($container);

        $response = $controller($request);
        self::assertTrue($response->isRedirect('/tools/vault/runtime-config'));

        return $session;
    }

    /**
     * @return MockObject&VaultSettingsRepositoryInterface
     */
    private function settingsRepository(?VaultSettings $stored): VaultSettingsRepositoryInterface
    {
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->method('findByScope')->willReturn($stored);

        return $settings;
    }
}

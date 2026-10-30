<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Form\VaultRuntimeConfigType;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Ui\VaultPageFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

/**
 * Admin screen that edits the database-backed runtime configuration.
 */
#[AsController]
final class VaultRuntimeConfigController extends AbstractController
{
    use VaultCsrfTrait;
    use VaultPagesTrait;

    private const SESSION_ENCRYPTION_KEY_ONE_TIME = 'vault.encryption_key.one_time';

    /**
     * Stores the runtime config collaborators, the config-storage flag and the access settings.
     */
    public function __construct(
        private readonly VaultRuntimeConfigProvider $configProvider,
        private readonly VaultRuntimeConfigWriter $configWriter,
        private readonly VaultSettingsRepositoryInterface $settingsRepository,
        private readonly TranslatorInterface $translator,
        private readonly VaultRuntimeConfigResolver $configResolver,
        private readonly bool $databaseEnabled,
        private readonly bool $allowUnauthenticated = false,
        private readonly ?VaultPageFactory $pageFactory = null,
    ) {
    }

    /**
     * Renders and handles the runtime configuration form.
     */
    #[Route(path: '/tools/vault/runtime-config', name: 'nowo_vault_runtime_config', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'runtime_config'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->databaseEnabled) {
            throw $this->createNotFoundException();
        }

        $this->denyUnlessAdmin();

        $routeName = $this->runtimeConfigRouteName();

        if ($request->isMethod('POST')) {
            $this->denyUnlessValidCsrf('vault_runtime_config', $request);

            $stored         = $this->settingsRepository->findByScope();
            $hasDbKey       = $this->hasEncryptionKey($stored?->getEncryptionKey());
            $hasYamlKey     = $this->configResolver->hasYamlEncryptionKey();
            $hasExistingKey = $hasDbKey || $hasYamlKey;

            if ($request->request->has('generate_encryption_key')) {
                if ($hasExistingKey) {
                    // Replacing a key that already encrypts items needs nowo:vault:reencrypt --persist-new-key.
                    $this->addFlash('info', $this->translator->trans($hasDbKey ? 'vault.flash.runtime_config.key_exists' : 'vault.flash.runtime_config.key_exists_yaml', [], 'NowoVaultBundle'));

                    return $this->redirectToRoute($routeName);
                }

                if (!$this->configWriter->canStoreEncryptionKey()) {
                    $this->addFlash('error', $this->translator->trans('vault.flash.runtime_config.key_storage_unavailable', [], 'NowoVaultBundle'));

                    return $this->redirectToRoute($routeName);
                }

                $key = SodiumVaultPayloadCryptographer::generateKeyBase64();
                $this->configWriter->update(['encryption_key' => $key]);
                $this->addFlash('success', $this->translator->trans('vault.flash.runtime_config.key_generated', [], 'NowoVaultBundle'));
                $this->storeOneTimeEncryptionKey($request, $key);

                return $this->redirectToRoute($routeName);
            }

            $maxBytes = $request->request->getInt('max_attachment_bytes');
            if ($maxBytes < 0) {
                $this->addFlash('error', $this->translator->trans('vault.flash.runtime_config.max_attachment_invalid', [], 'NowoVaultBundle'));

                return $this->redirectToRoute($routeName);
            }

            $level = trim($request->request->getString('password_level'));
            if ($level === '') {
                $level = 'medium';
            }

            $update = [
                'max_attachment_bytes' => $maxBytes,
                'password_field'       => ['level' => $level],
            ];

            if (!$hasExistingKey) {
                $encryptionKey = trim($request->request->getString('encryption_key'));
                if ($encryptionKey !== '') {
                    if (!$this->configWriter->canStoreEncryptionKey()) {
                        $this->addFlash('error', $this->translator->trans('vault.flash.runtime_config.key_storage_unavailable', [], 'NowoVaultBundle'));

                        return $this->redirectToRoute($routeName);
                    }

                    try {
                        VaultRuntimeConfigWriter::assertValidEncryptionKey($encryptionKey);
                    } catch (InvalidArgumentException) {
                        $this->addFlash('error', $this->translator->trans('vault.flash.runtime_config.key_invalid', [], 'NowoVaultBundle'));

                        return $this->redirectToRoute($routeName);
                    }

                    $update['encryption_key'] = $encryptionKey;
                }
            }

            $this->configWriter->update($update);

            if (!$hasExistingKey && isset($update['encryption_key'])) {
                $this->addFlash('success', $this->translator->trans('vault.flash.runtime_config.key_stored', [], 'NowoVaultBundle'));
                $this->storeOneTimeEncryptionKey($request, $update['encryption_key']);
            } else {
                $this->addFlash('success', $this->translator->trans('vault.flash.runtime_config.saved', [], 'NowoVaultBundle'));
            }

            return $this->redirectToRoute($routeName);
        }

        $stored    = $this->settingsRepository->findByScope();
        $dbValues  = $stored?->getValues() ?? [];
        $effective = $this->configProvider->get();
        $storedKey = $stored?->getEncryptionKey();
        $config    = $this->configProvider->get();

        $oneTimeKey    = $this->pullOneTimeEncryptionKey($request);
        $hasDbKey      = $this->hasEncryptionKey($storedKey);
        $hasYamlKey    = $this->configResolver->hasYamlEncryptionKey();
        $canStoreKey   = $this->configWriter->canStoreEncryptionKey();
        $maxBytes      = (int) ($dbValues['max_attachment_bytes'] ?? $effective['max_attachment_bytes'] ?? 512_000);
        $passwordLevel = (string) ($dbValues['password_field']['level'] ?? $effective['password_field']['level'] ?? 'medium');

        $keyMode = match (true) {
            $oneTimeKey !== null     => 'one_time',
            $hasDbKey || $hasYamlKey => 'configured',
            !$canStoreKey            => 'unavailable',
            default                  => 'editable',
        };

        // REQ-TWIG-005: same flat field names and token id; the POST branch above keeps validating them.
        $form = $this->createForm(VaultRuntimeConfigType::class, [
            'max_attachment_bytes' => $maxBytes,
            'password_level'       => $passwordLevel,
        ], [
            'action'                 => $this->generateUrl($routeName),
            'encryption_key_mode'    => $keyMode,
            'encryption_key_display' => $oneTimeKey ?? '',
        ]);

        $dashboardRoute = is_string($config['dashboard_route'] ?? null) ? $config['dashboard_route'] : null;
        $pages          = $this->pages();
        $updatedAt      = $stored?->getUpdatedAt();
        $view           = $form->createView();

        return $this->render($this->template(), $pages->context([
            'page'                  => $pages->runtimeConfig($view, $updatedAt instanceof DateTimeImmutable ? $updatedAt->format('Y-m-d H:i:s') . ' UTC' : null, $dashboardRoute),
            'routes'                => $config['routes'],
            'dashboardRoute'        => $dashboardRoute,
            'runtimeConfigRoute'    => $routeName,
            'dbUpdatedAt'           => $stored?->getUpdatedAt(),
            'maxAttachmentBytes'    => $maxBytes,
            'passwordLevel'         => $passwordLevel,
            'hasDbEncryptionKey'    => $hasDbKey,
            'hasYamlEncryptionKey'  => $hasYamlKey,
            'canStoreEncryptionKey' => $canStoreKey,
            'encryptionKeyDisplay'  => $oneTimeKey,
            'showOneTimeKeyWarning' => $oneTimeKey !== null,
            'form'                  => $view,
        ]));
    }

    private function denyUnlessAdmin(): void
    {
        if ($this->allowUnauthenticated) {
            return;
        }

        /** @var list<string> $adminRoles */
        $adminRoles = $this->configProvider->get()['security']['admin_roles'];

        foreach ($adminRoles as $role) {
            if ($this->isGranted($role)) {
                return;
            }
        }

        throw $this->createAccessDeniedException();
    }

    private function runtimeConfigRouteName(): string
    {
        $name = $this->configProvider->get()['routes']['runtime_config']['name'] ?? null;

        if (!is_string($name) || $name === '') {
            throw new LogicException('nowo_vault.routes.runtime_config.name is not configured.');
        }

        return $name;
    }

    private function template(): string
    {
        $template = $this->configProvider->get()['templates']['runtime_config'] ?? null;

        if (!is_string($template) || $template === '') {
            return '@NowoVaultBundle/vault/runtime_config.html.twig';
        }

        return $template;
    }

    private function hasEncryptionKey(?string $encryptionKey): bool
    {
        return is_string($encryptionKey) && $encryptionKey !== '';
    }

    private function storeOneTimeEncryptionKey(Request $request, string $key): void
    {
        $request->getSession()->set(self::SESSION_ENCRYPTION_KEY_ONE_TIME, $key);
    }

    private function pullOneTimeEncryptionKey(Request $request): ?string
    {
        $session = $request->getSession();
        if (!$session->has(self::SESSION_ENCRYPTION_KEY_ONE_TIME)) {
            return null;
        }

        $key = trim((string) $session->get(self::SESSION_ENCRYPTION_KEY_ONE_TIME));
        $session->remove(self::SESSION_ENCRYPTION_KEY_ONE_TIME);

        return $key !== '' ? $key : null;
    }
}

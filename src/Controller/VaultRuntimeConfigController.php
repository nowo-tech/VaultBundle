<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use LogicException;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

#[IsGranted('IS_AUTHENTICATED')]
final class VaultRuntimeConfigController extends AbstractController
{
    use VaultCsrfTrait;

    private const SESSION_ENCRYPTION_KEY_ONE_TIME = 'vault.encryption_key.one_time';

    public function __construct(
        private readonly VaultRuntimeConfigProvider $configProvider,
        private readonly VaultRuntimeConfigWriter $configWriter,
        private readonly VaultSettingsRepositoryInterface $settingsRepository,
        private readonly TranslatorInterface $translator,
        private readonly bool $databaseEnabled,
    ) {
    }

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
            $hasExistingKey = $this->hasEncryptionKey($stored?->getEncryptionKey());

            if ($request->request->has('generate_encryption_key')) {
                if ($hasExistingKey) {
                    $this->addFlash('info', $this->translator->trans('vault.flash.runtime_config.key_exists', [], 'NowoVaultBundle'));

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

        $oneTimeKey = $this->pullOneTimeEncryptionKey($request);

        return $this->render($this->template(), [
            'layout'                => $config['templates']['layout'],
            'routes'                => $config['routes'],
            'dashboardRoute'        => is_string($config['dashboard_route'] ?? null) ? $config['dashboard_route'] : null,
            'runtimeConfigRoute'    => $routeName,
            'dbUpdatedAt'           => $stored?->getUpdatedAt(),
            'maxAttachmentBytes'    => (int) ($dbValues['max_attachment_bytes'] ?? $effective['max_attachment_bytes'] ?? 512_000),
            'passwordLevel'         => (string) ($dbValues['password_field']['level'] ?? $effective['password_field']['level'] ?? 'medium'),
            'hasDbEncryptionKey'    => $this->hasEncryptionKey($storedKey),
            'encryptionKeyDisplay'  => $oneTimeKey,
            'showOneTimeKeyWarning' => $oneTimeKey !== null,
        ]);
    }

    private function denyUnlessAdmin(): void
    {
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

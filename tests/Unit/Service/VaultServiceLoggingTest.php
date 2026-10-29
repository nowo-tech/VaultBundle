<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthenticatorInterface;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\BrowserExtension\VaultLoginDomainMatcher;
use Nowo\VaultBundle\Command\PurgeExtensionTokensCommand;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Dto\VaultBrowserExtensionLoginMatch;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultBrowserExtensionAuthService;
use Nowo\VaultBundle\Service\VaultBrowserExtensionLoginResolver;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultSharedItemResolver;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\RecordingLogger;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_column;
use function array_map;
use function array_shift;
use function range;

/**
 * Log records (REQ-OBS-001) and injected clock (REQ-DI-001) of the services touched by the req100 changes.
 */
final class VaultServiceLoggingTest extends TestCase
{
    private const USERNAME = 'alice@example.test';
    private const PASSWORD = 'REDACTED-TEST-password';

    public function testExtensionLoginLogsOutcomeWithoutCredentials(): void
    {
        $user   = new TestUser('7', self::USERNAME);
        $logger = new RecordingLogger();
        $saved  = null;
        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->method('save')->willReturnCallback(static function (VaultExtensionToken $token) use (&$saved): void {
            $saved = $token;
        });
        $tokens->method('findByUser')->willReturn([
            new VaultExtensionToken('h1', new DateTimeImmutable('+1 hour'), $user),
            new VaultExtensionToken('h2', new DateTimeImmutable('+1 hour'), $user),
        ]);

        $results = [
            VaultBrowserExtensionAuthResult::failure('invalid'),
            VaultBrowserExtensionAuthResult::success($user),
            VaultBrowserExtensionAuthResult::success($user),
        ];
        $authenticator = $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturnCallback(static function () use (&$results): VaultBrowserExtensionAuthResult {
            return array_shift($results);
        });
        $access  = $this->createMock(VaultAccessCheckerInterface::class);
        $allowed = [true, false];
        $access->method('canAccess')->willReturnCallback(static function () use (&$allowed): bool {
            return array_shift($allowed) ?? false;
        });

        $clock   = new MockClock('2031-02-03 04:05:06');
        $service = new VaultBrowserExtensionAuthService($authenticator, $tokens, new EventDispatcher(), 600, $access, null, $clock, $logger);

        self::assertNull($service->login(self::USERNAME, self::PASSWORD));
        $issued = $service->login(self::USERNAME, self::PASSWORD);
        self::assertIsArray($issued);
        // Expiry comes from the injected clock + TTL.
        self::assertSame('2031-02-03T04:15:06+00:00', $issued['expiresAt']);
        self::assertInstanceOf(VaultExtensionToken::class, $saved);
        self::assertSame('2031-02-03 04:15:06', $saved->getExpiresAt()->format('Y-m-d H:i:s'));
        self::assertNull($service->login(self::USERNAME, self::PASSWORD));
        self::assertSame(2, $service->revokeAllFor($user));

        self::assertSame([
            'Vault browser extension login rejected.',
            'Vault browser extension token issued.',
            'Vault browser extension login rejected.',
            'Vault browser extension tokens revoked.',
        ], $logger->messages());
        self::assertSame(['invalid_credentials', null, 'access_denied', null], array_map(
            static fn (array $record): mixed => $record['context']['reason'] ?? null,
            $logger->records,
        ));
        self::assertSame('7', $logger->records[1]['context']['user_id']);
        self::assertSame(2, $logger->records[3]['context']['count']);
        self::assertStringNotContainsString(self::USERNAME, $logger->dump());
        self::assertStringNotContainsString(self::PASSWORD, $logger->dump());
        self::assertStringNotContainsString($issued['token'], $logger->dump());
    }

    public function testResolveUserTouchesTokenWithInjectedClock(): void
    {
        $user   = new TestUser('8');
        $entity = new VaultExtensionToken('hash', new DateTimeImmutable('2040-01-01'), $user);
        $entity->touch(new DateTimeImmutable('2031-01-01 00:00:00'));
        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->method('findValidByTokenHash')->willReturn($entity);
        $tokens->expects(self::exactly(2))->method('save')->with($entity);

        $clock   = new MockClock('2031-01-01 00:10:00');
        $service = new VaultBrowserExtensionAuthService(
            $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class),
            $tokens,
            new EventDispatcher(),
            600,
            clock: $clock,
        );

        self::assertSame($user, $service->resolveUser('plain'));
        self::assertSame('2031-01-01 00:10:00', $entity->getLastUsedAt()?->format('Y-m-d H:i:s'));

        // Within the debounce interval of the mocked clock: no save.
        $clock->sleep(60);
        $service->resolveUser('plain');
        self::assertSame('2031-01-01 00:10:00', $entity->getLastUsedAt()?->format('Y-m-d H:i:s'));

        $clock->sleep(300);
        $service->resolveUser('plain');
        self::assertSame('2031-01-01 00:16:00', $entity->getLastUsedAt()?->format('Y-m-d H:i:s'));
    }

    public function testLoginResolverCapsDecryptedItemsAndWarns(): void
    {
        $user          = new TestUser('9');
        $cryptographer = new SodiumVaultPayloadCryptographer(SodiumVaultPayloadCryptographer::generateKeyBase64());
        $items         = array_map(
            static fn (int $i): VaultItem => new VaultItem(
                VaultItemType::Login,
                'Login ' . $i,
                $user,
                $cryptographer->encrypt(['username' => 'u' . $i, 'password' => 'REDACTED-TEST', 'websites' => ['https://example.test']]),
            ),
            range(1, 5),
        );
        $repo = $this->createMock(VaultItemRepositoryInterface::class);
        $repo->method('findByCreatorAndItemType')->willReturn($items);
        $grants = $this->createMock(VaultGrantRepositoryInterface::class);
        $grants->method('findGrantedResourceIds')->willReturn([]);
        $teams  = new NullVaultTeamMembershipResolver();
        $logger = new RecordingLogger();

        $resolver = new VaultBrowserExtensionLoginResolver(
            $repo,
            new VaultSharedItemResolver($grants, $repo, $teams),
            new VaultAccessGuard($grants, $teams, new EventDispatcher()),
            $cryptographer,
            new VaultLoginDomainMatcher(),
            3,
            $logger,
        );

        $matches = $resolver->resolveForDomain($user, 'example.test');
        self::assertSame(['Login 1', 'Login 2', 'Login 3'], array_map(static fn (VaultBrowserExtensionLoginMatch $match): string => $match->title, $matches));
        self::assertSame(['Vault browser extension login lookup truncated.'], $logger->messages());
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['bundle' => 'nowo_vault', 'action' => 'extension_logins', 'user_id' => '9', 'available' => 5, 'scanned' => 3], $logger->records[0]['context']);
        self::assertStringNotContainsString('REDACTED-TEST', $logger->dump());

        // Under the cap: nothing truncated, nothing logged; default logger is a no-op.
        $quiet = new VaultBrowserExtensionLoginResolver(
            $repo,
            new VaultSharedItemResolver($grants, $repo, $teams),
            new VaultAccessGuard($grants, $teams, new EventDispatcher()),
            $cryptographer,
            new VaultLoginDomainMatcher(),
            2,
        );
        self::assertCount(2, $quiet->resolveForDomain($user, 'example.test'));
    }

    public function testLoginResolverSkipsEmptyHostForeignItemsAndOtherSites(): void
    {
        $user          = new TestUser('9');
        $stranger      = new TestUser('10');
        $cryptographer = new SodiumVaultPayloadCryptographer(SodiumVaultPayloadCryptographer::generateKeyBase64());
        $own           = new VaultItem(VaultItemType::Login, 'Own', $user, $cryptographer->encrypt(['username' => 'me', 'password' => 'REDACTED-TEST', 'websites' => ['https://example.test/login']]));
        $otherSite     = new VaultItem(VaultItemType::Login, 'Other', $user, $cryptographer->encrypt(['websites' => ['https://elsewhere.test']]));
        $foreign       = new VaultItem(VaultItemType::Login, 'Foreign', $stranger, $cryptographer->encrypt(['websites' => ['https://example.test']]));

        $repo = $this->createMock(VaultItemRepositoryInterface::class);
        $repo->method('findByCreatorAndItemType')->willReturn([$own, $otherSite]);
        $repo->method('findByIdsForViewerAndItemType')->willReturn([$foreign, $own]);
        $grants = $this->createMock(VaultGrantRepositoryInterface::class);
        // The foreign item looks shared but no grant matches it, so the access guard denies it.
        $grants->method('findGrantedResourceIds')->willReturn([$foreign->getId()]);
        $teams = new NullVaultTeamMembershipResolver();

        $resolver = new VaultBrowserExtensionLoginResolver(
            $repo,
            new VaultSharedItemResolver($grants, $repo, $teams),
            new VaultAccessGuard($grants, $teams, new EventDispatcher()),
            $cryptographer,
            new VaultLoginDomainMatcher(),
        );

        self::assertSame([], $resolver->resolveForDomain($user, '   '));
        $matches = $resolver->resolveForDomain($user, 'EXAMPLE.test');
        self::assertCount(1, $matches);
        self::assertSame('Own', $matches[0]->title);
        self::assertSame('me', $matches[0]->username);
    }

    public function testGrantServiceLogsIdentifiersOnly(): void
    {
        $repo = $this->createMock(VaultGrantRepositoryInterface::class);
        $repo->method('findOne')->willReturnOnConsecutiveCalls(null, null);
        $logger  = new RecordingLogger();
        $service = new VaultGrantService($repo, $logger);

        $grant        = $service->grant(VaultResourceType::Item, 'item-1', GranteeType::User, 'user-2', VaultPermission::Read, new TestUser('1'));
        $existingRepo = $this->createMock(VaultGrantRepositoryInterface::class);
        $existingRepo->method('findOne')->willReturn($grant);
        (new VaultGrantService($existingRepo, $logger))->grant(VaultResourceType::Item, 'item-1', GranteeType::User, 'user-2', VaultPermission::Write, new TestUser('1'));
        $service->revoke($grant);

        self::assertSame(['Vault grant created.', 'Vault grant updated.', 'Vault grant revoked.'], $logger->messages());
        self::assertSame(['grant_create', 'grant_update', 'grant_revoke'], array_column(array_column($logger->records, 'context'), 'action'));
        self::assertSame([
            'bundle'        => 'nowo_vault',
            'action'        => 'grant_update',
            'resource_type' => VaultResourceType::Item->value,
            'resource_id'   => 'item-1',
            'grantee_type'  => GranteeType::User->value,
            'grantee_id'    => 'user-2',
            'permission'    => VaultPermission::Write->value,
        ], $logger->records[1]['context']);
        self::assertInstanceOf(VaultGrant::class, $grant);
    }

    public function testTrashServiceLogsPurges(): void
    {
        $user   = new TestUser('1');
        $item   = new VaultItem(VaultItemType::SecureNote, 'Secret title', $user, 'ciphertext');
        $folder = new VaultFolder('Private folder', $user);
        $logger = new RecordingLogger();
        $items  = $this->createMock(VaultItemRepositoryInterface::class);
        $items->expects(self::once())->method('remove')->with($item);
        $folders = $this->createMock(VaultFolderRepositoryInterface::class);
        $folders->expects(self::once())->method('remove')->with($folder);

        $service = new VaultTrashService($items, $folders, $logger);
        $service->purgeItem($item);
        $service->purgeFolder($folder);

        self::assertSame(['Vault item purged.', 'Vault folder purged.'], $logger->messages());
        self::assertSame($item->getId(), $logger->records[0]['context']['item_id']);
        self::assertSame($folder->getId(), $logger->records[1]['context']['folder_id']);
        self::assertStringNotContainsString('Secret title', $logger->dump());
        self::assertStringNotContainsString('Private folder', $logger->dump());
    }

    public function testRuntimeConfigWriterLogsKeysNeverValues(): void
    {
        $key      = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->method('findByScope')->willReturnOnConsecutiveCalls(null, new VaultSettings(), new VaultSettings());
        $logger   = new RecordingLogger();
        $provider = new VaultRuntimeConfigProvider(VaultRuntimeConfigFactory::baseline(), true, $settings, new ArrayAdapter());
        $writer   = new VaultRuntimeConfigWriter(true, $settings, $provider, true, $logger);

        $writer->update(['encryption_key' => $key, 'max_attachment_bytes' => 123_456]);
        $writer->update(['max_attachment_bytes' => 654_321]);
        $writer->reset();

        self::assertSame([
            'Vault runtime configuration updated.',
            'Vault runtime configuration updated.',
            'Vault runtime configuration reset to the YAML baseline.',
        ], $logger->messages());
        self::assertSame(['max_attachment_bytes'], $logger->records[0]['context']['keys']);
        self::assertTrue($logger->records[0]['context']['encryption_key_change']);
        self::assertFalse($logger->records[1]['context']['encryption_key_change']);
        self::assertSame('notice', $logger->records[2]['level']);
        self::assertStringNotContainsString($key, $logger->dump());
        self::assertStringNotContainsString('123456', $logger->dump());
    }

    public function testPurgeCommandLogsCount(): void
    {
        $repository = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $repository->method('removeExpired')->willReturn(4);
        $logger = new RecordingLogger();

        (new CommandTester(new PurgeExtensionTokensCommand($repository, $logger)))->execute([]);

        self::assertSame(['Expired vault browser extension tokens purged.'], $logger->messages());
        self::assertSame(4, $logger->records[0]['context']['count']);
    }
}

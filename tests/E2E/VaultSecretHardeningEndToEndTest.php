<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultItemRevealedEvent;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Tests\App\EventSubscriber\RevealRecorderSubscriber;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

use function bin2hex;
use function json_decode;
use function json_encode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * 1.15.2 hardening on the default test kernel: no decrypted secret in the item HTML, empty secret fields keep the
 * stored value, the per-field reveal endpoint, ITEM_REVEALED events (web + extension /logins) and no-store headers.
 */
final class VaultSecretHardeningEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testNoStoredSecretIsRenderedInViewOrEditHtml(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $client->loginUser($alice);
        $marker = $this->marker();

        foreach (VaultItemType::cases() as $type) {
            $item = $this->createItem($alice, 'Item ' . $type->value, $type, [
                'password' => $marker, 'note' => $marker, 'content' => $marker, 'cardNumber' => $marker,
                'cvv'      => $marker, 'cardPin' => $marker, 'documentNumber' => $marker,
            ]);

            foreach (['/tools/vault/items/' . $item->getId(), '/tools/vault/items/' . $item->getId() . '/edit'] as $url) {
                $client->request('GET', $url);
                self::assertResponseIsSuccessful();
                $html = (string) $client->getResponse()->getContent();
                self::assertStringNotContainsString($marker, $html, $type->value . ' ' . $url);
                self::assertStringContainsString('data-vault-secret-field="note"', $html, $type->value);
                self::assertStringContainsString('data-vault-reveal-url="/tools/vault/items/' . $item->getId() . '/reveal/FIELD"', $html);
                self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
                self::assertSame('no-cache', $client->getResponse()->headers->get('Pragma'));
            }
        }
    }

    public function testEmptySecretKeepsTheStoredValueAndClearEmptiesIt(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $client->loginUser($alice);
        $marker = $this->marker();
        $item   = $this->createItem($alice, 'Mail', VaultItemType::Login, ['password' => $marker, 'note' => $marker]);

        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/edit');
        $form    = $crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Mail 2']);
        self::assertSame('', $form['vault_item_form[password]']->getValue());
        $client->submit($form);
        self::assertResponseRedirects();

        $payload = $this->payloadOf($item->getId());
        self::assertSame($marker, $payload['password']);
        self::assertSame($marker, $payload['note']);
        self::assertSame('Mail 2', $this->findItem($item->getId())?->getTitle());

        // A typed value replaces the stored one; an explicitly cleared field is emptied.
        $crawler                                   = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/edit');
        $form                                      = $crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[password]' => 'replaced-placeholder']);
        $values                                    = $form->getPhpValues();
        $values['vault_item_form']['clearSecrets'] = ['note'];
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $payload = $this->payloadOf($item->getId());
        self::assertSame('replaced-placeholder', $payload['password']);
        self::assertSame('', $payload['note']);
    }

    public function testRevealReturnsOneSecretFieldWithEventAndNoStore(): void
    {
        $client                           = $this->bootClient();
        $alice                            = $this->createUser('alice@example.com');
        $bob                              = $this->createUser('bob@example.com');
        $marker                           = $this->marker();
        $item                             = $this->createItem($alice, 'Mail', VaultItemType::Login, ['password' => $marker]);
        $url                              = '/tools/vault/items/' . $item->getId() . '/reveal/';
        RevealRecorderSubscriber::$events = [];

        $client->loginUser($alice);
        $token = $this->csrf($client, 'vault_reveal_' . $item->getId());

        $client->request('POST', $url . 'password');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request('POST', $url . 'password', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame(['field' => 'password', 'value' => $marker], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, RevealRecorderSubscriber::$events);
        self::assertSame($item->getId(), RevealRecorderSubscriber::$events[0]->getItemId());
        self::assertSame(['password'], RevealRecorderSubscriber::$events[0]->getFields());
        self::assertSame(VaultItemRevealedEvent::CHANNEL_WEB, RevealRecorderSubscriber::$events[0]->getChannel());
        self::assertSame((string) $alice->getId(), RevealRecorderSubscriber::$events[0]->getUserId());

        // Not a secret field of the type, or not a field at all: 404.
        $client->request('POST', $url . 'username', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('POST', $url . 'cvv', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('GET', $url . 'password');
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);

        // Another user: denied with the default deny_status (403), no event.
        $client->loginUser($bob);
        $bobToken = $this->csrf($client, 'vault_reveal_' . $item->getId());
        $client->request('POST', $url . 'password', server: ['HTTP_X_CSRF_TOKEN' => $bobToken]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringNotContainsString($marker, (string) $client->getResponse()->getContent());
        self::assertCount(1, RevealRecorderSubscriber::$events);
    }

    public function testExtensionLoginsAreNoStoreAndDispatchOneEventPerCredential(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $this->createItem($alice, 'One', VaultItemType::Login, ['websites' => ['https://example.com']]);
        $this->createItem($alice, 'Two', VaultItemType::Login, ['websites' => ['https://example.com/login']]);
        RevealRecorderSubscriber::$events = [];

        $client->request('POST', '/api/vault/extension/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['username' => 'alice@example.com', 'password' => 'secret'], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
        /** @var array{token: string} $login */
        $login = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('GET', '/api/vault/extension/logins?domain=example.com', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $login['token']]);
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(2, RevealRecorderSubscriber::$events);
        foreach (RevealRecorderSubscriber::$events as $event) {
            self::assertSame(VaultItemRevealedEvent::CHANNEL_EXTENSION, $event->getChannel());
            self::assertSame(['username', 'password', 'websites'], $event->getFields());
            self::assertSame(VaultItemType::Login, $event->getItemType());
        }
    }

    private function marker(): string
    {
        return 'MARK' . bin2hex(random_bytes(12));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(string $id): array
    {
        $item = $this->findItem($id);
        self::assertNotNull($item);

        return self::getContainer()->get(VaultPayloadCryptographerInterface::class)->decrypt($item->getCiphertext());
    }
}

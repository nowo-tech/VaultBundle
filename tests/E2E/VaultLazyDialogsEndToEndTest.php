<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

use function array_unique;
use function count;
use function fwrite;
use function getenv;
use function sprintf;
use function strlen;

use const STDERR;

/**
 * Lazy dialogs (symfony-beacon owner rule 2026-11-23, "every dialog loads its content on demand"): the vault lists
 * print ONE shared confirm dialog per table (UiKit 2.68 confirm_shared) and one for the folder / tag chips, never one
 * per row. Each row keeps its own hidden POST form, target and CSRF token; no secret value reaches the page.
 */
final class VaultLazyDialogsEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    private const ROWS = 50;

    private const SECRET = 'S3cret-lazy-dialogs-never-in-html';

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testFiftyRowsPrintOneSharedConfirmDialogAndKeepTheirOwnFormsAndTokens(): void
    {
        [$client, $items, $folders] = $this->seed();

        $page = $client->request('GET', '/tools/vault/items');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        if (getenv('VAULT_LAZY_MEASURE') !== false) {
            fwrite(STDERR, sprintf("\n[lazy-dialogs] vault items, %d rows: %d bytes, %d confirm dialogs\n", self::ROWS, strlen($html), $page->filterXPath('//dialog[contains(concat(" ", normalize-space(@class), " "), " nowo-ui-confirm ")]')->count()));
        }

        // 1 dialog for the table + 1 for the folder / tag chips, whatever the number of rows.
        self::assertCount(2, $page->filterXPath('//dialog[contains(concat(" ", normalize-space(@class), " "), " nowo-ui-confirm ")]'));
        self::assertCount(1, $page->filterXPath('//dialog[@id="nowo-ui-table-confirm"][@data-nowo-confirm-shared]'));
        self::assertCount(1, $page->filterXPath('//dialog[@id="vault-chips-confirm"][@data-nowo-confirm-shared]'));
        self::assertCount(0, $page->filterXPath('//dialog//form'), 'No dialog carries a form: the rows keep theirs.');
        self::assertStringNotContainsString(self::SECRET, $html, 'No secret value on the list page.');

        $tokens = [];
        foreach ($items as $item) {
            $form     = $this->rowForm($page, 'nowo-ui-table-confirm', '/tools/vault/items/' . $item->getId() . '/trash');
            $tokens[] = (string) $form->filterXPath('descendant::input[@name="_token"]')->attr('value');
        }
        self::assertCount(self::ROWS, array_unique($tokens), 'Every row has its own CSRF token.');

        foreach ($folders as $folder) {
            $this->rowForm($page, 'vault-chips-confirm', '/tools/vault/folders/' . $folder->getId() . '/trash');
        }
        self::assertGreaterThan(0, $page->filterXPath('//*[@data-nowo-confirm-target="vault-chips-confirm"][contains(@data-nowo-confirm-form, "-form-")]//ancestor::*[@data-testid="vault-tag"]')->count(), 'The tag chips open the chips dialog.');

        // The confirm of row 7 posts row 7's own form: its token trashes row 7 only, another row's token is refused.
        $target = $items[7];
        $client->request('POST', '/tools/vault/items/' . $target->getId() . '/trash', ['_token' => $tokens[8]]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->request('POST', '/tools/vault/items/' . $target->getId() . '/trash', ['_token' => $tokens[7]]);
        self::assertResponseRedirects();
        self::assertTrue($this->findItem($target->getId())?->isDeleted());
        self::assertFalse($this->findItem($items[8]->getId())?->isDeleted());
    }

    public function testTrashAndSharedListsShareOneDialogAndAccessIsDeniedAsBefore(): void
    {
        [$client, $items] = $this->seed();
        foreach ([$items[0], $items[1], $items[2]] as $item) {
            $client->request('POST', '/tools/vault/items/' . $item->getId() . '/trash', ['_token' => $this->csrf($client, 'vault_trash_' . $item->getId())]);
            self::assertResponseRedirects();
        }

        $trash = $client->request('GET', '/tools/vault/trash');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $trash->filterXPath('//dialog[contains(concat(" ", normalize-space(@class), " "), " nowo-ui-confirm ")]'), 'One purge dialog for three rows.');
        foreach ([$items[0], $items[1], $items[2]] as $item) {
            $this->rowForm($trash, 'nowo-ui-table-confirm', '/tools/vault/items/' . $item->getId() . '/purge');
        }

        // Another user: no row, no dialog, and the endpoints refuse as before.
        $bob = $this->createUser('bob@example.com');
        $client->loginUser($bob);
        $list = $client->request('GET', '/tools/vault/items');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($items[5]->getId(), (string) $client->getResponse()->getContent());
        self::assertCount(0, $list->filterXPath('//dialog[@id="nowo-ui-table-confirm"]'));
        $client->request('POST', '/tools/vault/items/' . $items[5]->getId() . '/trash', ['_token' => $this->csrf($client, 'vault_trash_' . $items[5]->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->findItem($items[5]->getId())?->isDeleted());
    }

    /**
     * @return array{0: KernelBrowser, 1: list<VaultItem>, 2: list<VaultFolder>}
     */
    private function seed(): array
    {
        $client  = $this->bootClient();
        $alice   = $this->createUser('alice@example.com');
        $folders = [$this->createFolder($alice, 'Work'), $this->createFolder($alice, 'Home'), $this->createFolder($alice, 'Bank')];
        $items   = [];
        for ($i = 0; $i < self::ROWS; ++$i) {
            $items[] = $this->createItem($alice, sprintf('Login %02d', $i), payload: ['password' => self::SECRET], folder: $folders[$i % 3]);
        }
        $tags = self::getContainer()->get(VaultTagService::class);
        self::assertInstanceOf(VaultTagService::class, $tags);
        $tags->syncItemTags($this->findItem($items[0]->getId()) ?? self::fail(), $this->managed($alice), ['work', 'mail']);
        $client->loginUser($alice);

        return [$client, $items, $folders];
    }

    /**
     * The toggle that opens the shared dialog `$dialog` for `$action`, and the row's own hidden POST form it submits.
     */
    private function rowForm(Crawler $page, string $dialog, string $action): Crawler
    {
        $form = $page->filterXPath('//form[@method="post"][@action="' . $action . '"]');
        self::assertCount(1, $form, $action);
        $id = (string) $form->attr('id');
        self::assertNotSame('', $id);
        self::assertCount(0, $page->filterXPath('//dialog//form[@action="' . $action . '"]'), 'The row form is not inside a dialog.');
        $toggle = $page->filterXPath('//*[@data-nowo-confirm-form="' . $id . '"]');
        self::assertCount(1, $toggle, 'One toggle per row form.');
        self::assertSame($dialog, $toggle->attr('data-nowo-confirm-target'));
        self::assertSame(1, count($form->filterXPath('descendant::input[@name="_token"]')));

        return $form;
    }
}

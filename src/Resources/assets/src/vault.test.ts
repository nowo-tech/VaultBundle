import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();

async function boot(html: string): Promise<typeof import('./vault')> {
    document.body.innerHTML = html;
    vi.resetModules();
    const mod = await import('./vault');
    document.dispatchEvent(new Event('DOMContentLoaded'));

    return mod;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
}

const GENERATED = 'test-generated-value';
// Generated per run: the test only checks that the page token is echoed in the request header.
const TOKEN = crypto.randomUUID(); // ggignore: random per-run test value, not a credential
const URL = '/tools/vault/password/generate';

const DIALOG_HTML = `
    <input name="vault_item_form[password]" value="">
    <button data-vault-password-generator>open</button>
    <dialog id="vault-password-generator" open>
        <div data-vault-password-modal data-vault-password-url="${URL}" data-vault-password-csrf="${TOKEN}">
            <form data-vault-password-form>
                <input data-vault-password-output value="">
                <button type="button" data-vault-password-regenerate>again</button>
                <button type="button" data-vault-password-copy>copy</button>
            </form>
            <span data-vault-password-strength></span>
        </div>
        <button data-vault-password-fill>fill</button>
    </dialog>
    <button data-vault-folder-toggle aria-expanded="false">folder</button>
    <form data-vault-folder-form hidden><input type="hidden" name="_token"><input name="name"></form>
`;

describe('vault manage UI', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValue({ ok: true, json: async () => ({ password: GENERATED, strength: 'strong' }) });
        vi.stubGlobal('fetch', fetchMock);
        // jsdom has no <dialog> modal support: emulate showModal() / close().
        HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
            this.setAttribute('open', '');
        };
        HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
            this.removeAttribute('open');
        };
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    // Each import adds listeners on the shared jsdom document: counts below are relative.
    it('regenerates, copies, fills and closes from the content the dialog loaded', async () => {
        const writeText = vi.fn(async () => undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        await boot(DIALOG_HTML);

        const dialog = document.querySelector('#vault-password-generator') as HTMLDialogElement;
        const output = document.querySelector('[data-vault-password-output]') as HTMLInputElement;
        const field = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement;
        // The first password comes with the fragment; the opener no longer asks for one.
        (document.querySelector('[data-vault-password-generator]') as HTMLElement).click();
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();

        (document.querySelector('[data-vault-password-regenerate]') as HTMLElement).click();
        await flush();
        expect(fetchMock).toHaveBeenCalledWith(URL, expect.objectContaining({ method: 'POST' }));
        expect(fetchMock.mock.calls[0][1].headers['X-CSRF-Token']).toBe(TOKEN);
        expect(output.value).toBe(GENERATED);
        expect(document.querySelector('[data-vault-password-strength]')?.textContent).toBe('strong');

        (document.querySelector('[data-vault-password-copy]') as HTMLElement).click();
        await flush();
        expect(writeText).toHaveBeenCalledWith(GENERATED);

        const submit = new Event('submit', { cancelable: true, bubbles: true });
        document.querySelector('[data-vault-password-form]')?.dispatchEvent(submit);
        expect(submit.defaultPrevented).toBe(true);

        (document.querySelector('[data-vault-password-fill]') as HTMLElement).click();
        expect(field.value).toBe(GENERATED);
        expect(dialog.open).toBe(false);

        // Fill on a closed dialog: nothing to close.
        (document.querySelector('[data-vault-password-fill]') as HTMLElement).click();
        expect(dialog.open).toBe(false);
    });

    it('does not copy or fill when nothing was generated', async () => {
        const writeText = vi.fn(async () => undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        await boot(DIALOG_HTML);
        const field = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement;
        field.value = 'kept';

        (document.querySelector('[data-vault-password-copy]') as HTMLElement).click();
        (document.querySelector('[data-vault-password-fill]') as HTMLElement).click();
        await flush();

        expect(writeText).not.toHaveBeenCalled();
        expect(field.value).toBe('kept');
    });

    it('toggles the folder form and its aria-expanded state', async () => {
        await boot(DIALOG_HTML);
        const toggle = document.querySelector('[data-vault-folder-toggle]') as HTMLElement;
        const folderForm = document.querySelector('[data-vault-folder-form]') as HTMLElement;

        toggle.click();
        expect(folderForm.hidden).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(document.activeElement).toBe(folderForm.querySelector('input[name="name"]'));
        toggle.click();
        expect(folderForm.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    it('tolerates missing optional elements', async () => {
        await boot(`
            <div data-vault-password-modal data-vault-password-url="${URL}"><button data-vault-password-regenerate>again</button></div>
            <button data-vault-folder-toggle>folder</button>
            <form data-vault-folder-form hidden></form>
        `);

        (document.querySelector('[data-vault-password-regenerate]') as HTMLElement).click();
        (document.querySelector('[data-vault-folder-toggle]') as HTMLElement).click();
        await flush();

        expect(fetchMock).toHaveBeenCalled();
        expect(fetchMock.mock.calls.at(-1)?.[1].headers['X-CSRF-Token']).toBeUndefined();
    });

    it('binds nothing without the generator content or a folder form', async () => {
        await boot('<button data-vault-password-regenerate>open</button><button data-vault-folder-toggle>folder</button>');

        (document.querySelector('[data-vault-password-regenerate]') as HTMLElement).click();
        (document.querySelector('[data-vault-folder-toggle]') as HTMLElement).click();
        await flush();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('reads the generator configuration from the dialog', async () => {
        const mod = await boot('');
        const dialog = document.createElement('dialog');

        expect(mod.readGeneratorConfig(null)).toBeNull();
        expect(mod.readGeneratorConfig(dialog)).toBeNull();
        dialog.dataset.vaultPasswordUrl = URL;
        expect(mod.readGeneratorConfig(dialog)).toEqual({ url: URL, csrfToken: undefined });
        dialog.setAttribute('data-vault-password-csrf', TOKEN); // ggignore: random per-run test value, not a credential
        expect(mod.readGeneratorConfig(dialog)).toEqual({ url: URL, csrfToken: TOKEN });

        await expect(mod.generatePassword(null)).rejects.toThrow('Password generator URL is not configured.');
    });
});

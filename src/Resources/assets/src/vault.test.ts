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
    <dialog data-vault-password-modal data-vault-password-url="${URL}" data-vault-password-csrf="${TOKEN}">
        <form data-vault-password-form>
            <input data-vault-password-output value="">
            <button type="button" data-vault-password-regenerate>again</button>
            <button type="button" data-vault-password-copy>copy</button>
        </form>
        <span data-vault-password-strength></span>
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

    // Each import adds a DOMContentLoaded listener on the shared jsdom document: counts below are relative.
    it('opens the dialog, generates, regenerates, copies, fills and closes', async () => {
        vi.useFakeTimers();
        const writeText = vi.fn(async () => undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        await boot(DIALOG_HTML);

        const dialog = document.querySelector('[data-vault-password-modal]') as HTMLDialogElement;
        const output = document.querySelector('[data-vault-password-output]') as HTMLInputElement;
        const field = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement;

        (document.querySelector('[data-vault-password-generator]') as HTMLElement).click();
        vi.runAllTimers();
        await flush();
        expect(dialog.open).toBe(true);
        expect(output.value).toBe(GENERATED);
        expect(document.querySelector('[data-vault-password-strength]')?.textContent).toBe('strong');
        expect(fetchMock).toHaveBeenCalledWith(URL, expect.objectContaining({ method: 'POST' }));
        expect(fetchMock.mock.calls[0][1].headers['X-CSRF-Token']).toBe(TOKEN);

        // Already open (UiKit opened it): not opened twice.
        const showModal = vi.spyOn(dialog, 'showModal');
        (document.querySelector('[data-vault-password-generator]') as HTMLElement).click();
        vi.runAllTimers();
        expect(showModal).not.toHaveBeenCalled();

        const callsBefore = fetchMock.mock.calls.length;
        (document.querySelector('[data-vault-password-regenerate]') as HTMLElement).click();
        await flush();
        expect(fetchMock.mock.calls.length).toBeGreaterThan(callsBefore);

        (document.querySelector('[data-vault-password-copy]') as HTMLElement).click();
        await flush();
        expect(writeText).toHaveBeenCalledWith(GENERATED);

        const submit = new Event('submit', { cancelable: true });
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
            <button data-vault-password-generator>open</button>
            <div data-vault-password-modal data-vault-password-url="${URL}"></div>
            <button data-vault-folder-toggle>folder</button>
            <form data-vault-folder-form hidden></form>
        `);

        (document.querySelector('[data-vault-password-generator]') as HTMLElement).click();
        (document.querySelector('[data-vault-folder-toggle]') as HTMLElement).click();
        await flush();

        expect(fetchMock).toHaveBeenCalled();
        expect(fetchMock.mock.calls.at(-1)?.[1].headers['X-CSRF-Token']).toBeUndefined();
    });

    it('binds nothing without a dialog or a folder form', async () => {
        await boot('<button data-vault-password-generator>open</button><button data-vault-folder-toggle>folder</button>');

        (document.querySelector('[data-vault-password-generator]') as HTMLElement).click();
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

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

const MODAL_HTML = `
    <input name="vault_item_form[password]" value="">
    <button data-vault-password-generator>open</button>
    <div data-vault-password-modal hidden>
        <input data-vault-password-output value="">
        <span data-vault-password-strength></span>
        <button data-vault-password-close>close</button>
        <button data-vault-password-regenerate>again</button>
        <button data-vault-password-copy>copy</button>
        <button data-vault-password-fill>fill</button>
    </div>
    <button data-vault-generate-inline>inline</button>
    <button data-vault-folder-toggle>folder</button>
    <form data-vault-folder-form hidden></form>
`;

const GENERATED = 'test-generated-value';
// Generated per run: the test only checks that the page token is echoed in the request header.
const CSRF = crypto.randomUUID();

describe('vault manage UI', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValue({ ok: true, json: async () => ({ password: GENERATED, strength: 'strong' }) });
        vi.stubGlobal('fetch', fetchMock);
        window.VAULT_PASSWORD_URL = '/tools/vault/password/generate';
        window.VAULT_PASSWORD_CSRF_TOKEN = CSRF; // ggignore: random per-run test value, not a credential
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        delete window.VAULT_PASSWORD_URL;
        delete window.VAULT_PASSWORD_CSRF_TOKEN;
    });

    // Each import adds a DOMContentLoaded listener on the shared jsdom document: counts below are relative.
    it('opens the modal, generates, regenerates, copies, fills and closes', async () => {
        const writeText = vi.fn(async () => undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        await boot(MODAL_HTML);

        const modal = document.querySelector('[data-vault-password-modal]') as HTMLElement;
        const output = document.querySelector('[data-vault-password-output]') as HTMLInputElement;
        const field = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement;

        (document.querySelector('[data-vault-password-generator]') as HTMLElement).click();
        await flush();
        expect(modal.hidden).toBe(false);
        expect(output.value).toBe(GENERATED);
        expect(document.querySelector('[data-vault-password-strength]')?.textContent).toBe('strong');
        expect(fetchMock).toHaveBeenCalledWith('/tools/vault/password/generate', expect.objectContaining({ method: 'POST' }));
        expect(fetchMock.mock.calls[0][1].headers['X-CSRF-Token']).toBe(CSRF);

        const callsBefore = fetchMock.mock.calls.length;
        (document.querySelector('[data-vault-password-regenerate]') as HTMLElement).click();
        await flush();
        expect(fetchMock.mock.calls.length).toBeGreaterThan(callsBefore);

        (document.querySelector('[data-vault-password-copy]') as HTMLElement).click();
        await flush();
        expect(writeText).toHaveBeenCalledWith(GENERATED);

        (document.querySelector('[data-vault-password-fill]') as HTMLElement).click();
        expect(field.value).toBe(GENERATED);
        expect(modal.hidden).toBe(true);

        modal.hidden = false;
        (document.querySelector('[data-vault-password-close]') as HTMLElement).click();
        expect(modal.hidden).toBe(true);
    });

    it('does not copy or fill when nothing was generated', async () => {
        const writeText = vi.fn(async () => undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        await boot(MODAL_HTML);
        const field = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement;
        field.value = 'kept';

        (document.querySelector('[data-vault-password-copy]') as HTMLElement).click();
        (document.querySelector('[data-vault-password-fill]') as HTMLElement).click();
        await flush();

        expect(writeText).not.toHaveBeenCalled();
        expect(field.value).toBe('kept');
    });

    it('fills the password field inline and toggles the folder form', async () => {
        await boot(MODAL_HTML);
        const field = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement;
        const folderForm = document.querySelector('[data-vault-folder-form]') as HTMLElement;

        (document.querySelector('[data-vault-generate-inline]') as HTMLElement).click();
        await flush();
        expect(field.value).toBe(GENERATED);

        (document.querySelector('[data-vault-folder-toggle]') as HTMLElement).click();
        expect(folderForm.hidden).toBe(false);
        (document.querySelector('[data-vault-folder-toggle]') as HTMLElement).click();
        expect(folderForm.hidden).toBe(true);
    });

    it('tolerates missing optional elements', async () => {
        await boot(`
            <button data-vault-password-generator>open</button>
            <button data-vault-password-close>close</button>
            <button data-vault-password-fill>fill</button>
            <button data-vault-generate-inline>inline</button>
            <button data-vault-folder-toggle>folder</button>
        `);

        (document.querySelector('[data-vault-password-generator]') as HTMLElement).click();
        (document.querySelector('[data-vault-password-close]') as HTMLElement).click();
        (document.querySelector('[data-vault-password-fill]') as HTMLElement).click();
        (document.querySelector('[data-vault-generate-inline]') as HTMLElement).click();
        (document.querySelector('[data-vault-folder-toggle]') as HTMLElement).click();
        await flush();

        // DOMContentLoaded listeners of earlier imports also bind to this DOM, so only check that it generated.
        expect(fetchMock).toHaveBeenCalled();
    });

    it('generatePassword rejects without a configured URL', async () => {
        const mod = await boot('');
        delete window.VAULT_PASSWORD_URL;

        await expect(mod.generatePassword()).rejects.toThrow('Password generator URL is not configured.');
    });
});

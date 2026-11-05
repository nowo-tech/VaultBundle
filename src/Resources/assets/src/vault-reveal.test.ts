import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { bindRevealButtons, fetchSecretField, readRevealConfig } from './vault-reveal';

const fetchMock = vi.fn();
// Generated per run: placeholder values only (no real secret).
const TOKEN = crypto.randomUUID(); // ggignore: random per-run test value, not a credential
const VALUE = `placeholder-${crypto.randomUUID()}`;
const URL = '/tools/vault/items/00000000-0000-4000-8000-000000000000/reveal/FIELD';

const PAGE = `
    <div hidden data-vault-reveal data-vault-reveal-url="${URL}" data-vault-reveal-csrf="${TOKEN}"
         data-vault-reveal-show="Show" data-vault-reveal-hide="Hide" data-vault-reveal-error="Error"></div>
    <input type="password" name="vault_item_form[password]" value="" data-vault-secret-field="password">
    <textarea name="vault_item_form[note]" data-vault-secret-field="note"></textarea>
`;

async function flush(): Promise<void> {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
}

function okResponse(value: unknown): Response {
    return { ok: true, json: async () => ({ field: 'password', value }) } as unknown as Response;
}

describe('vault-reveal', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('reads nothing without the reveal element or without a token', () => {
        document.body.innerHTML = '<input data-vault-secret-field="password">';
        expect(readRevealConfig(document)).toBeNull();
        expect(bindRevealButtons(document)).toEqual([]);

        document.body.innerHTML = `<div data-vault-reveal data-vault-reveal-url="${URL}"></div>`;
        expect(readRevealConfig(document)).toBeNull();
    });

    it('falls back to default labels', () => {
        document.body.innerHTML = `<div data-vault-reveal data-vault-reveal-url="${URL}" data-vault-reveal-csrf="${TOKEN}"></div>`;
        expect(readRevealConfig(document)).toEqual({ url: URL, csrfToken: TOKEN, showLabel: 'Show', hideLabel: 'Hide', errorLabel: 'Error' });
    });

    it('posts the field with the CSRF header and returns the value', async () => {
        fetchMock.mockResolvedValueOnce(okResponse(VALUE));
        document.body.innerHTML = PAGE;
        const config = readRevealConfig(document)!;

        await expect(fetchSecretField(config, 'password')).resolves.toBe(VALUE);
        expect(fetchMock).toHaveBeenCalledWith(URL.replace('FIELD', 'password'), expect.objectContaining({
            method: 'POST',
            cache: 'no-store',
            headers: expect.objectContaining({ 'X-CSRF-Token': TOKEN }),
        }));
    });

    it('returns an empty string for a non-string value and throws on an error status', async () => {
        document.body.innerHTML = PAGE;
        const config = readRevealConfig(document)!;
        fetchMock.mockResolvedValueOnce(okResponse(42));
        await expect(fetchSecretField(config, 'note')).resolves.toBe('');
        fetchMock.mockResolvedValueOnce({ ok: false } as Response);
        await expect(fetchSecretField(config, 'note')).rejects.toThrow('Reveal failed.');
    });

    it('shows, hides and wipes on pagehide', async () => {
        document.body.innerHTML = PAGE;
        const inputs = bindRevealButtons(document);
        expect(inputs).toHaveLength(2);

        const password = document.querySelector('input[data-vault-secret-field]') as HTMLInputElement;
        const button = document.querySelector('[data-vault-reveal-button="password"]') as HTMLButtonElement;
        expect(password.value).toBe('');
        expect(button.textContent).toBe('Show');

        fetchMock.mockResolvedValueOnce(okResponse(VALUE));
        button.click();
        await flush();
        expect(password.value).toBe(VALUE);
        expect(password.type).toBe('text');
        expect(button.textContent).toBe('Hide');
        expect(button.getAttribute('aria-pressed')).toBe('true');

        button.click();
        expect(password.value).toBe('');
        expect(password.type).toBe('password');
        expect(button.textContent).toBe('Show');

        const note = document.querySelector('textarea') as HTMLTextAreaElement;
        const noteButton = document.querySelector('[data-vault-reveal-button="note"]') as HTMLButtonElement;
        fetchMock.mockResolvedValueOnce(okResponse(VALUE));
        noteButton.click();
        await flush();
        expect(note.value).toBe(VALUE);

        window.dispatchEvent(new Event('pagehide'));
        expect(note.value).toBe('');
        expect(noteButton.textContent).toBe('Show');
    });

    it('shows the error label when the reveal fails', async () => {
        document.body.innerHTML = PAGE;
        bindRevealButtons(document);
        const button = document.querySelector('[data-vault-reveal-button="password"]') as HTMLButtonElement;
        fetchMock.mockRejectedValueOnce(new Error('network'));
        button.click();
        await flush();
        expect(button.textContent).toBe('Error');
        expect((document.querySelector('input[data-vault-secret-field]') as HTMLInputElement).value).toBe('');
    });
});

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { attachmentFilename, bindRevealButtons, downloadAttachment, fetchSecretField, readRevealConfig, readRevealExtras } from './vault-reveal';

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

describe('vault-reveal 1.16.0', () => {
    const EXTRAS = `
        <div hidden data-vault-reveal data-vault-reveal-url="${URL}" data-vault-reveal-csrf="${TOKEN}"
             data-vault-reveal-show="Show" data-vault-reveal-hide="Hide" data-vault-reveal-error="Error"
             data-vault-reveal-copy="Copy it" data-vault-reveal-copied="Done" data-vault-reveal-auto-hide="2"
             data-vault-attachment-url="/tools/vault/items/x/attachment"></div>
        <input type="password" value="" data-vault-secret-field="password">
        <button type="button" data-vault-attachment-download>Download</button>
    `;

    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('reads the extras with defaults', () => {
        document.body.innerHTML = `<div data-vault-reveal data-vault-reveal-url="${URL}" data-vault-reveal-csrf="${TOKEN}"></div>`;
        expect(readRevealExtras(document)).toEqual({ copyLabel: 'Copy', copiedLabel: 'Copied', autoHideSeconds: 0, attachmentUrl: null });
        document.body.innerHTML = EXTRAS;
        expect(readRevealExtras(document)).toEqual({ copyLabel: 'Copy it', copiedLabel: 'Done', autoHideSeconds: 2, attachmentUrl: '/tools/vault/items/x/attachment' });
        document.body.innerHTML = '';
        expect(readRevealExtras(document).autoHideSeconds).toBe(0);
    });

    it('posts the copy purpose, joins lists and refuses another encoding', async () => {
        document.body.innerHTML = EXTRAS;
        const config = readRevealConfig(document)!;
        fetchMock.mockResolvedValueOnce({ ok: true, json: async () => ({ encoding: 'plain', value: ['a', 1, 'b'] }) } as unknown as Response);
        await expect(fetchSecretField(config, 'websites', 'copy')).resolves.toBe('a\nb');
        expect(fetchMock).toHaveBeenCalledWith(URL.replace('FIELD', 'websites'), expect.objectContaining({ body: 'purpose=copy' }));
        fetchMock.mockResolvedValueOnce({ ok: true, json: async () => ({ encoding: 'ciphertext', value: 'x' }) } as unknown as Response);
        await expect(fetchSecretField(config, 'password')).rejects.toThrow('Unsupported encoding.');
    });

    it('copies to the clipboard, auto-hides and wipes when the tab is hidden', async () => {
        vi.useFakeTimers();
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        document.body.innerHTML = EXTRAS;
        bindRevealButtons(document);
        const input = document.querySelector('input') as HTMLInputElement;
        const show = document.querySelector('[data-vault-reveal-button="password"]') as HTMLButtonElement;
        const copy = document.querySelector('[data-vault-copy-button="password"]') as HTMLButtonElement;

        fetchMock.mockResolvedValueOnce({ ok: true, json: async () => ({ encoding: 'plain', value: VALUE }) } as unknown as Response);
        copy.click();
        await flush();
        expect(writeText).toHaveBeenCalledWith(VALUE);
        expect(copy.textContent).toBe('Done');
        expect(input.value).toBe('');

        fetchMock.mockRejectedValueOnce(new Error('network'));
        copy.click();
        await flush();
        expect(copy.textContent).toBe('Error');

        fetchMock.mockResolvedValue({ ok: true, json: async () => ({ value: VALUE }) } as unknown as Response);
        show.click();
        await flush();
        expect(input.value).toBe(VALUE);
        show.click();
        show.click();
        await flush();
        vi.advanceTimersByTime(2000);
        expect(input.value).toBe('');

        show.click();
        await flush();
        expect(input.value).toBe(VALUE);
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        expect(input.value).toBe(VALUE);
        Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        expect(input.value).toBe('');
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
    });

    it('downloads the attachment through an object URL', async () => {
        const createObjectURL = vi.fn().mockReturnValue('blob:x');
        const revokeObjectURL = vi.fn();
        vi.stubGlobal('URL', { createObjectURL, revokeObjectURL });
        document.body.innerHTML = EXTRAS;
        bindRevealButtons(document);
        const clicks: string[] = [];
        HTMLAnchorElement.prototype.click = function (this: HTMLAnchorElement) { clicks.push(this.download); };
        fetchMock.mockResolvedValueOnce({
            ok: true,
            blob: async () => new Blob(['x']),
            headers: { get: () => "attachment; filename=\"r_sum_.pdf\"; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf" },
        } as unknown as Response);
        (document.querySelector('[data-vault-attachment-download]') as HTMLButtonElement).click();
        await flush();
        expect(clicks).toEqual(['résumé.pdf']);
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:x');
        expect(fetchMock).toHaveBeenCalledWith('/tools/vault/items/x/attachment', expect.objectContaining({ method: 'POST', headers: { 'X-CSRF-Token': TOKEN } }));

        fetchMock.mockResolvedValueOnce({ ok: false } as Response);
        const button = document.querySelector('[data-vault-attachment-download]') as HTMLButtonElement;
        button.click();
        await flush();
        expect(button.textContent).toBe('Error');
    });

    it('parses attachment names', () => {
        expect(attachmentFilename(null)).toBe('attachment.bin');
        expect(attachmentFilename('attachment; filename="a.pdf"')).toBe('a.pdf');
        expect(attachmentFilename('attachment; filename=""')).toBe('attachment.bin');
        expect(attachmentFilename("attachment; filename=\"b.pdf\"; filename*=UTF-8''%E0%A4%A")).toBe('b.pdf');
    });

    it('does not bind the download without an attachment URL', async () => {
        document.body.innerHTML = `<div data-vault-reveal data-vault-reveal-url="${URL}" data-vault-reveal-csrf="${TOKEN}"></div><button data-vault-attachment-download>D</button>`;
        bindRevealButtons(document);
        (document.querySelector('[data-vault-attachment-download]') as HTMLButtonElement).click();
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
        fetchMock.mockResolvedValueOnce({ ok: false } as Response);
        await expect(downloadAttachment(readRevealConfig(document)!, '/x')).rejects.toThrow('Download failed.');
    });
});

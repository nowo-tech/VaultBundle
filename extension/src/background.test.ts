import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { VaultLoginMatch } from './types.js';

type Listener = (message: unknown, sender: chrome.runtime.MessageSender, sendResponse: (r: unknown) => void) => boolean;

const api = {
    getConfig: vi.fn(),
    isSessionValid: vi.fn(),
    loginsForDomain: vi.fn(),
};

let listener: Listener;
let removedListener: (tabId: number) => void;

const PW_A = 'test-placeholder-a';
const PW_B = 'test-placeholder-b';

const LOGINS: VaultLoginMatch[] = [
    { id: 'a', title: 'Mail', username: 'ann', password: PW_A },
    { id: 'b', title: 'Bank', username: 'bob', password: PW_B },
];

async function boot(withTabs = true): Promise<void> {
    vi.resetModules();
    const ext = {
        runtime: {
            id: 'ext-id',
            onMessage: { addListener: (fn: Listener) => { listener = fn; } },
        },
        tabs: withTabs ? { onRemoved: { addListener: (fn: (id: number) => void) => { removedListener = fn; } } } : undefined,
    };
    vi.doMock('./runtime.js', () => ({ ext }));
    vi.doMock('./api.js', () => api);
    await import('./background.js');
}

function send(message: unknown, sender: Partial<chrome.runtime.MessageSender>): Promise<{ async: boolean; response: unknown }> {
    return new Promise((resolve) => {
        let async = false;
        let settled = false;
        const respond = (response: unknown) => {
            settled = true;
            resolve({ async, response });
        };
        async = listener(message, { id: 'ext-id', ...sender } as chrome.runtime.MessageSender, respond);
        if (!async && !settled) {
            resolve({ async, response: undefined });
        }
    });
}

const tab = (id: number, url: string) => ({ tab: { id, url } as chrome.tabs.Tab });

describe('extension background', () => {
    beforeEach(() => {
        api.getConfig.mockReset().mockResolvedValue({ baseUrl: 'https://vault.test', token: 't', expiresAt: '' });
        api.isSessionValid.mockReset().mockReturnValue(true);
        api.loginsForDomain.mockReset().mockResolvedValue({ logins: LOGINS });
    });

    it('ignores messages from other extensions and unknown types', async () => {
        await boot();
        expect(listener({ type: 'vault:getLogins' }, { id: 'other' } as chrome.runtime.MessageSender, vi.fn())).toBe(false);
        expect((await send({ type: 'nope' }, {})).async).toBe(false);
        expect((await send(null, {})).async).toBe(false);
    });

    it('returns login summaries without passwords and fills only the same host', async () => {
        await boot();
        const result = await send({ type: 'vault:getLogins' }, tab(7, 'https://mail.example.test/login'));
        expect(result.async).toBe(true);
        expect(result.response).toEqual({
            ok: true,
            logins: [
                { id: 'a', title: 'Mail', username: 'ann' },
                { id: 'b', title: 'Bank', username: 'bob' },
            ],
        });
        expect(api.loginsForDomain).toHaveBeenCalledWith('mail.example.test', 'https');

        expect((await send({ type: 'vault:fill', id: 'b' }, tab(7, 'https://mail.example.test/x'))).response)
            .toEqual({ ok: true, username: 'bob', password: PW_B });
        expect((await send({ type: 'vault:fill', id: 'b' }, tab(7, 'https://evil.test/'))).response).toEqual({ ok: false });
        expect((await send({ type: 'vault:fill', id: 'zz' }, tab(7, 'https://mail.example.test/'))).response).toEqual({ ok: false });
        expect((await send({ type: 'vault:fill' }, tab(7, 'https://mail.example.test/'))).response).toEqual({ ok: false });
        expect((await send({ type: 'vault:fill', id: 'a' }, { url: 'https://mail.example.test/' })).response).toEqual({ ok: false });

        removedListener(7);
        expect((await send({ type: 'vault:fill', id: 'a' }, tab(7, 'https://mail.example.test/'))).response).toEqual({ ok: false });
    });

    it('rejects unsupported pages, signed-out sessions and API errors', async () => {
        await boot(false);
        expect((await send({ type: 'vault:getLogins' }, tab(1, 'chrome://settings'))).response)
            .toEqual({ ok: false, error: 'Unsupported page.' });
        expect((await send({ type: 'vault:getLogins' }, { url: 'https://a.test/' })).response)
            .toEqual({ ok: false, error: 'Unsupported page.' });

        api.isSessionValid.mockReturnValue(false);
        expect((await send({ type: 'vault:getLogins' }, tab(1, 'http://a.test/'))).response)
            .toEqual({ ok: false, error: 'Not signed in.' });

        api.isSessionValid.mockReturnValue(true);
        api.loginsForDomain.mockResolvedValue({});
        expect((await send({ type: 'vault:getLogins' }, tab(1, 'http://a.test/'))).response).toEqual({ ok: true, logins: [] });
        expect(api.loginsForDomain).toHaveBeenLastCalledWith('a.test', 'http');
    });

    it('reports the session status', async () => {
        await boot();
        expect((await send({ type: 'vault:sessionStatus' }, {})).response)
            .toEqual({ ok: true, signedIn: true, baseUrl: 'https://vault.test' });

        api.getConfig.mockRejectedValue(new Error('storage down'));
        expect((await send({ type: 'vault:sessionStatus' }, {})).response).toEqual({ ok: false, error: 'storage down' });
    });
});

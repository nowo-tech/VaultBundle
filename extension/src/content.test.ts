import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { LoginFields, VaultLoginSummary } from './types.js';

type Callback = (response: unknown) => void;

const fields = { usernameField: null, passwordField: document.createElement('input') } as LoginFields;
const loginFields = {
    debounce: vi.fn((fn: () => void) => fn),
    fillCredentials: vi.fn(),
    findLoginFields: vi.fn(),
    removeAutofillPanel: vi.fn(),
    renderAutofillPanel: vi.fn(),
};
const runtime: { lastError?: { message: string }; sendMessage: ReturnType<typeof vi.fn> } = { sendMessage: vi.fn() };

async function boot(): Promise<void> {
    vi.resetModules();
    vi.doMock('./login-fields.js', () => loginFields);
    vi.doMock('./runtime.js', () => ({ ext: { runtime } }));
    await import('./content.js');
}

describe('extension content script', () => {
    beforeEach(() => {
        for (const fn of Object.values(loginFields)) {
            fn.mockClear();
        }
        loginFields.debounce.mockImplementation((fn: () => void) => fn);
        runtime.sendMessage.mockReset();
        delete runtime.lastError;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('removes the panel when the page has no login form', async () => {
        loginFields.findLoginFields.mockReturnValue(null);
        await boot();

        expect(loginFields.removeAutofillPanel).toHaveBeenCalled();
        expect(runtime.sendMessage).not.toHaveBeenCalled();
    });

    it('asks for logins, renders them and fills after a selection', async () => {
        loginFields.findLoginFields.mockReturnValue(fields);
        const logins: VaultLoginSummary[] = [{ id: 'a', title: 'Mail', username: 'ann' }];
        runtime.sendMessage.mockImplementation((message: { type: string }, cb: Callback) => {
            if (message.type === 'vault:getLogins') {
                cb({ ok: true, logins });
            } else {
                cb({ ok: true, username: 'ann', password: 'pw' });
            }
        });
        await boot();

        expect(runtime.sendMessage).toHaveBeenCalledWith({ type: 'vault:getLogins' }, expect.any(Function));
        expect(loginFields.renderAutofillPanel).toHaveBeenCalledWith(document, logins, fields, expect.any(Function));

        const onSelect = loginFields.renderAutofillPanel.mock.calls[0][3] as (l: VaultLoginSummary) => void;
        onSelect(logins[0]);
        expect(runtime.sendMessage).toHaveBeenLastCalledWith({ type: 'vault:fill', id: 'a' }, expect.any(Function));
        expect(loginFields.fillCredentials).toHaveBeenCalledWith({ username: 'ann', password: 'pw' }, fields);
    });

    it('does not fill when the background refuses or errors', async () => {
        loginFields.findLoginFields.mockReturnValue(fields);
        let fillResponse: unknown = { ok: false };
        runtime.sendMessage.mockImplementation((message: { type: string }, cb: Callback) => {
            cb(message.type === 'vault:getLogins' ? { ok: true } : fillResponse);
        });
        await boot();
        expect(loginFields.renderAutofillPanel.mock.calls[0][1]).toEqual([]);

        const onSelect = loginFields.renderAutofillPanel.mock.calls[0][3] as (l: VaultLoginSummary) => void;
        onSelect({ id: 'a', title: 't', username: 'u' });
        fillResponse = undefined;
        onSelect({ id: 'a', title: 't', username: 'u' });
        fillResponse = { ok: true };
        runtime.lastError = { message: 'gone' };
        onSelect({ id: 'a', title: 't', username: 'u' });
        expect(loginFields.fillCredentials).not.toHaveBeenCalled();

        delete runtime.lastError;
        onSelect({ id: 'a', title: 't', username: 'u' });
        expect(loginFields.fillCredentials).toHaveBeenCalledWith({ username: '', password: '' }, fields);
    });

    it('removes the panel on lastError or a failed lookup and rescans on DOM mutations', async () => {
        loginFields.findLoginFields.mockReturnValue(fields);
        runtime.sendMessage.mockImplementation((_m: unknown, cb: Callback) => cb({ ok: false }));
        await boot();
        expect(loginFields.removeAutofillPanel).toHaveBeenCalledTimes(1);

        // Observers of earlier imports are still attached to the shared document: compare counts, not totals.
        runtime.lastError = { message: 'no receiver' };
        runtime.sendMessage.mockImplementation((_m: unknown, cb: Callback) => cb(undefined));
        const sentBefore = runtime.sendMessage.mock.calls.length;
        const removedBefore = loginFields.removeAutofillPanel.mock.calls.length;
        document.body.appendChild(document.createElement('div'));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(runtime.sendMessage.mock.calls.length).toBeGreaterThan(sentBefore);
        expect(loginFields.removeAutofillPanel.mock.calls.length).toBeGreaterThan(removedBefore);
        expect(loginFields.debounce).toHaveBeenCalledWith(expect.any(Function), 300);
    });
});

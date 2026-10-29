import { beforeEach, describe, expect, it, vi } from 'vitest';

const api = {
    getConfig: vi.fn(),
    isSessionValid: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
};
const openOptionsPage = vi.fn();

const POPUP = `
    <p id="status"></p>
    <form id="login-form" hidden><input id="username"><input id="password"></form>
    <div id="session-view" hidden><span id="identifier"></span><button id="logout">out</button></div>
    <a id="options-link" href="#">options</a>
`;

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const el = (id: string) => document.getElementById(id) as HTMLElement;

async function boot(html = POPUP): Promise<void> {
    document.body.innerHTML = html;
    vi.resetModules();
    vi.doMock('./api.js', () => api);
    vi.doMock('./runtime.js', () => ({ ext: { runtime: { openOptionsPage } } }));
    await import('./popup.js');
    await tick();
}

describe('extension popup', () => {
    beforeEach(() => {
        for (const fn of Object.values(api)) {
            fn.mockReset();
        }
        openOptionsPage.mockReset();
        api.getConfig.mockResolvedValue({ baseUrl: 'https://vault.test', token: 't', expiresAt: '' });
        api.isSessionValid.mockReturnValue(true);
        api.me.mockResolvedValue({ identifier: 'ann@example.test' });
        api.logout.mockResolvedValue(undefined);
    });

    it('asks for the vault URL first', async () => {
        api.getConfig.mockResolvedValue({ baseUrl: '', token: '', expiresAt: '' });
        await boot();
        expect(el('status').textContent).toBe('Set the vault URL in Options first.');
        expect(el('status').className).toBe('status error');
        expect(el('login-form').hidden).toBe(false);
        expect(el('session-view').hidden).toBe(true);
    });

    it('shows the login form when signed out', async () => {
        api.isSessionValid.mockReturnValue(false);
        await boot();
        expect(el('status').textContent).toBe('Sign in to autofill logins on this browser.');
        expect(el('status').className).toBe('status');
    });

    it('shows the session, falling back from identifier to userId', async () => {
        await boot();
        expect(el('identifier').textContent).toBe('ann@example.test');
        expect(el('session-view').hidden).toBe(false);
        expect(el('login-form').hidden).toBe(true);
        expect(el('status').textContent).toBe('Ready to autofill on matching sites.');

        api.me.mockResolvedValue({ userId: '42' });
        await boot();
        expect(el('identifier').textContent).toBe('42');

        api.me.mockResolvedValue({});
        await boot();
        expect(el('identifier').textContent).toBe('Signed in');
    });

    it('treats a failing profile call as an expired session', async () => {
        api.me.mockRejectedValue(new Error('401'));
        await boot();
        expect(el('status').textContent).toBe('Session expired. Sign in again.');
        expect(el('login-form').hidden).toBe(false);
    });

    it('signs in, clears the password and reports login errors', async () => {
        api.isSessionValid.mockReturnValue(false);
        await boot();
        (el('username') as HTMLInputElement).value = '  ann  ';
        (el('password') as HTMLInputElement).value = 'pw';
        api.login.mockResolvedValue({ token: 't', expiresAt: '' });
        api.isSessionValid.mockReturnValue(true);

        el('login-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        expect(api.login).toHaveBeenCalledWith('ann', 'pw');
        expect((el('password') as HTMLInputElement).value).toBe('');
        expect(el('status').textContent).toBe('Ready to autofill on matching sites.');

        api.login.mockRejectedValue(new Error('Invalid credentials.'));
        el('login-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        expect(el('status').textContent).toBe('Invalid credentials.');

        api.login.mockRejectedValue('weird');
        el('login-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        expect(el('status').textContent).toBe('Login failed.');
    });

    it('signs out and opens the options page', async () => {
        await boot();
        el('logout').click();
        await tick();
        expect(api.logout).toHaveBeenCalled();
        expect(el('status').textContent).toBe('Signed out.');
        expect(el('login-form').hidden).toBe(false);

        const click = new MouseEvent('click', { cancelable: true });
        el('options-link').dispatchEvent(click);
        expect(click.defaultPrevented).toBe(true);
        expect(openOptionsPage).toHaveBeenCalled();
    });

    it('tolerates a popup without any elements', async () => {
        api.getConfig.mockResolvedValue({ baseUrl: '', token: '', expiresAt: '' });
        await boot('');
        api.getConfig.mockResolvedValue({ baseUrl: 'https://vault.test', token: 't', expiresAt: '' });
        await boot('<form id="login-form"></form>');
        el('login-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();
        expect(api.login).toHaveBeenCalledWith('', '');
    });
});

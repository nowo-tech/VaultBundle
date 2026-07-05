import { getConfig, isSessionValid, login, logout, me } from './api.js';
import { ext } from './runtime.js';

const statusEl = document.getElementById('status');
const loginForm = document.getElementById('login-form') as HTMLFormElement | null;
const sessionView = document.getElementById('session-view');
const usernameInput = document.getElementById('username') as HTMLInputElement | null;
const passwordInput = document.getElementById('password') as HTMLInputElement | null;
const identifierEl = document.getElementById('identifier');
const logoutBtn = document.getElementById('logout');
const optionsLink = document.getElementById('options-link');

function setStatus(message: string, isError = false): void {
    if (!statusEl) {
        return;
    }

    statusEl.textContent = message;
    statusEl.className = isError ? 'status error' : 'status';
}

function showLogin(): void {
    if (loginForm) {
        loginForm.hidden = false;
    }
    if (sessionView) {
        sessionView.hidden = true;
    }
}

function showSession(identifier: string): void {
    if (loginForm) {
        loginForm.hidden = true;
    }
    if (sessionView) {
        sessionView.hidden = false;
    }
    if (identifierEl) {
        identifierEl.textContent = identifier;
    }
}

async function refresh(): Promise<void> {
    const config = await getConfig();
    if (!config.baseUrl) {
        setStatus('Set the vault URL in Options first.', true);
        showLogin();
        return;
    }

    if (!isSessionValid(config)) {
        showLogin();
        setStatus('Sign in to autofill logins on this browser.');
        return;
    }

    try {
        const profile = await me();
        showSession(profile.identifier || profile.userId || 'Signed in');
        setStatus('Ready to autofill on matching sites.');
    } catch {
        showLogin();
        setStatus('Session expired. Sign in again.', true);
    }
}

loginForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setStatus('Signing in…');

    try {
        await login(usernameInput?.value.trim() ?? '', passwordInput?.value ?? '');
        if (passwordInput) {
            passwordInput.value = '';
        }
        await refresh();
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Login failed.';
        setStatus(message, true);
    }
});

logoutBtn?.addEventListener('click', async () => {
    await logout();
    showLogin();
    setStatus('Signed out.');
});

optionsLink?.addEventListener('click', (event) => {
    event.preventDefault();
    ext.runtime.openOptionsPage();
});

void refresh();

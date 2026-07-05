import { requestJson } from './api-client.js';
import { ext } from './runtime.js';
import { isSessionValid } from './session.js';
import { STORAGE_KEYS } from './storage-keys.js';
import type { LoginTokenResponse, LoginsResponse, SessionConfig, VaultProfile } from './types.js';
import { normalizeBaseUrl } from './url.js';

export { STORAGE_KEYS } from './storage-keys.js';
export { isSessionValid } from './session.js';
export { normalizeBaseUrl } from './url.js';

export async function getConfig(): Promise<SessionConfig> {
    const data = await ext.storage.local.get(Object.values(STORAGE_KEYS));

    return {
        baseUrl: normalizeBaseUrl(String(data[STORAGE_KEYS.baseUrl] ?? '')),
        token: String(data[STORAGE_KEYS.token] ?? ''),
        expiresAt: String(data[STORAGE_KEYS.expiresAt] ?? ''),
    };
}

async function setSession(token: string, expiresAt: string): Promise<void> {
    await ext.storage.local.set({
        [STORAGE_KEYS.token]: token,
        [STORAGE_KEYS.expiresAt]: expiresAt,
    });
}

async function clearSession(): Promise<void> {
    await ext.storage.local.remove([STORAGE_KEYS.token, STORAGE_KEYS.expiresAt]);
}

async function apiRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
    const config = await getConfig();

    return requestJson<T>(config.baseUrl, path, {
        method: options.method,
        body: typeof options.body === 'string' ? options.body : undefined,
        headers: options.headers as Record<string, string> | undefined,
        token: config.token || undefined,
    });
}

export async function login(username: string, password: string): Promise<LoginTokenResponse> {
    const payload = await apiRequest<LoginTokenResponse>('/api/vault/extension/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password }),
    });

    await setSession(payload.token, payload.expiresAt);

    return payload;
}

export async function logout(): Promise<void> {
    const config = await getConfig();

    if (config.token) {
        try {
            await apiRequest('/api/vault/extension/logout', { method: 'POST' });
        } catch {
            // Ignore network errors during logout.
        }
    }

    await clearSession();
}

export async function me(): Promise<VaultProfile> {
    return apiRequest<VaultProfile>('/api/vault/extension/me');
}

export async function loginsForDomain(domain: string): Promise<LoginsResponse> {
    const query = new URLSearchParams({ domain });

    return apiRequest<LoginsResponse>(`/api/vault/extension/logins?${query.toString()}`);
}

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const storage: Record<string, string> = {
    nowoVaultBaseUrl: 'http://localhost:8023',
};

vi.mock('./runtime.js', () => ({
    ext: {
        storage: {
            local: {
                get: vi.fn(async (keys: string | string[]) => {
                    const list = Array.isArray(keys) ? keys : [keys];
                    const result: Record<string, string> = {};
                    for (const key of list) {
                        result[key] = storage[key] ?? '';
                    }
                    return result;
                }),
                set: vi.fn(async (values: Record<string, string>) => {
                    Object.assign(storage, values);
                }),
                remove: vi.fn(async (keys: string | string[]) => {
                    for (const key of Array.isArray(keys) ? keys : [keys]) {
                        delete storage[key];
                    }
                }),
            },
        },
    },
}));

describe('extension api', () => {
    beforeEach(() => {
        vi.resetModules();
        storage.nowoVaultBaseUrl = 'http://localhost:8023';
        delete storage.nowoVaultToken;
        delete storage.nowoVaultExpiresAt;
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('logs in and stores bearer token', async () => {
        globalThis.fetch = async () => new Response(
            JSON.stringify({ token: 'test-token', expiresAt: '2030-01-01T00:00:00+00:00' }),
            { status: 200 },
        );

        const { login } = await import('./api.js');
        const payload = await login('demo@example.com', 'demo');

        expect(payload.token).toBe('test-token');
        expect(storage.nowoVaultToken).toBe('test-token');
    });

    it('loads logins for a domain with authorization header', async () => {
        storage.nowoVaultToken = 'saved-token';
        storage.nowoVaultExpiresAt = '2030-01-01T00:00:00+00:00';

        globalThis.fetch = async (input, init) => {
            expect(String(input)).toContain('/api/vault/extension/logins?domain=login.example.com');
            expect((init?.headers as Record<string, string>).Authorization).toBe('Bearer saved-token');

            return new Response(JSON.stringify({
                domain: 'login.example.com',
                logins: [{ id: '1', title: 'Demo', username: 'user', password: 'pass', matchScore: 10 }],
            }), { status: 200 });
        };

        const { loginsForDomain } = await import('./api.js');
        const payload = await loginsForDomain('login.example.com');

        expect(payload.logins).toHaveLength(1);
    });

    it('clears session on logout', async () => {
        storage.nowoVaultToken = 'saved-token';
        storage.nowoVaultExpiresAt = '2030-01-01T00:00:00+00:00';
        globalThis.fetch = async () => new Response('', { status: 204 });

        const { logout } = await import('./api.js');
        await logout();

        expect(storage.nowoVaultToken).toBeUndefined();
        expect(storage.nowoVaultExpiresAt).toBeUndefined();
    });
});

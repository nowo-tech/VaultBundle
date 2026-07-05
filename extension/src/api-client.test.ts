import { describe, expect, it } from 'vitest';
import { requestJson } from './api-client.js';

describe('requestJson', () => {
    it('throws when base URL is empty', async () => {
        await expect(requestJson('', '/api/vault/extension/me')).rejects.toThrow(
            'Configure the vault URL in extension options.',
        );
    });

    it('returns parsed JSON on success', async () => {
        globalThis.fetch = async () => new Response(JSON.stringify({ identifier: 'demo' }), { status: 200 });

        const result = await requestJson<{ identifier: string }>('http://localhost:8023', '/api/vault/extension/me', {
            token: 'secret',
        });

        expect(result.identifier).toBe('demo');
    });

    it('throws API error message from payload', async () => {
        globalThis.fetch = async () => new Response(JSON.stringify({ error: 'Invalid credentials.' }), { status: 401 });

        await expect(
            requestJson('http://localhost:8023', '/api/vault/extension/login', { method: 'POST' }),
        ).rejects.toThrow('Invalid credentials.');
    });

    it('falls back to HTTP status when payload has no error field', async () => {
        globalThis.fetch = async () => new Response('not-json', { status: 500 });

        await expect(requestJson('http://localhost:8023', '/x')).rejects.toThrow('Request failed (500).');
    });
});

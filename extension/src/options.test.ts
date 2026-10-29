import { beforeEach, describe, expect, it, vi } from 'vitest';

const storage: Record<string, unknown> = {};
const local = {
    get: vi.fn(async (key: string) => ({ [key]: storage[key] })),
    set: vi.fn(async (values: Record<string, unknown>) => {
        Object.assign(storage, values);
    }),
};

async function boot(html: string): Promise<void> {
    document.body.innerHTML = html;
    vi.resetModules();
    vi.doMock('./runtime.js', () => ({ ext: { storage: { local } } }));
    await import('./options.js');
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const FORM = '<form id="options-form"><input id="base-url"></form><p id="status"></p>';

function submit(): Promise<void> {
    document.getElementById('options-form')?.dispatchEvent(new Event('submit', { cancelable: true }));

    return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('extension options page', () => {
    beforeEach(() => {
        for (const key of Object.keys(storage)) {
            delete storage[key];
        }
        local.set.mockClear();
    });

    it('loads the stored URL (empty when unset)', async () => {
        await boot(FORM);
        expect((document.getElementById('base-url') as HTMLInputElement).value).toBe('');

        storage.nowoVaultBaseUrl = 'https://vault.test';
        await boot(FORM);
        expect((document.getElementById('base-url') as HTMLInputElement).value).toBe('https://vault.test');
    });

    it('saves a secure URL and rejects plain http', async () => {
        await boot(FORM);
        const input = document.getElementById('base-url') as HTMLInputElement;
        const status = document.getElementById('status') as HTMLElement;

        input.value = 'http://vault.example.test';
        await submit();
        expect(status.textContent).toContain('Use an https:// vault URL');
        expect(local.set).not.toHaveBeenCalled();

        input.value = 'https://vault.example.test/';
        await submit();
        expect(status.textContent).toBe('Saved.');
        expect(storage.nowoVaultBaseUrl).toBe('https://vault.example.test');
    });

    it('works without status / input elements', async () => {
        await boot('<form id="options-form"></form>');
        await submit();
        expect(local.set).not.toHaveBeenCalled();

        storage.nowoVaultBaseUrl = 'https://v.test';
        await boot('<form id="options-form"><input id="base-url"></form>');
        await submit();
        expect(local.set).toHaveBeenCalledTimes(1);
    });

    it('does nothing without the form', async () => {
        await boot('');
        expect(local.get).toHaveBeenCalled();
    });
});

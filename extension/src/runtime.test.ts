import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

describe('extension runtime', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.unstubAllGlobals();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('prefers browser API when available', async () => {
        const fakeBrowser = { storage: { local: {} } };
        vi.stubGlobal('browser', fakeBrowser);
        vi.stubGlobal('chrome', { storage: { local: { marker: 'chrome' } } });

        const { ext } = await import('./runtime.js');
        expect(ext).toBe(fakeBrowser);
    });

    it('falls back to chrome API', async () => {
        vi.stubGlobal('browser', undefined);
        const fakeChrome = { storage: { local: { marker: 'chrome' } } };
        vi.stubGlobal('chrome', fakeChrome);

        const { ext } = await import('./runtime.js');
        expect(ext).toBe(fakeChrome);
    });
});

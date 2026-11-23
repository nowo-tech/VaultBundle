import { afterEach, describe, expect, it, vi } from 'vitest';

describe('vault entry', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.resetModules();
    });

    it('logs "script loaded" once, without a build time when none is injected', async () => {
        const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);

        await import('./vault');

        const lines = log.mock.calls.map((call) => String(call[0])).filter((line) => line.includes('script loaded'));
        expect(lines).toHaveLength(1);
        expect(lines[0]).toContain('[vault] script loaded');
        expect(lines[0]).not.toContain('build: ');
    });

    it('includes the injected build time', async () => {
        vi.stubGlobal('__VAULT_BUILD_TIME__', '2026-11-23 14:05 UTC');
        const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);

        await import('./vault');

        const lines = log.mock.calls.filter((call) => String(call[0]).includes('script loaded'));
        expect(lines).toHaveLength(1);
        expect(String(lines[0]?.[0])).toContain('[vault] script loaded, build: ');
        expect(String(lines[0]?.[0])).toContain('2026-11-23 14:05 UTC');
    });
});

import { afterEach, describe, expect, it, vi } from 'vitest';
import { createBundleLogger } from './logger';

describe('createBundleLogger', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('logs script loaded with and without build time', () => {
        const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);

        createBundleLogger('Vault', { buildTime: '2026-10-29' }).scriptLoaded();
        createBundleLogger('Vault').scriptLoaded();
        createBundleLogger('Vault', { buildTime: '' }).scriptLoaded();

        expect(log).toHaveBeenCalledTimes(3);
        expect(log.mock.calls[0][0]).toContain('[Vault] script loaded, build time: %c2026-10-29');
        expect(log.mock.calls[1][0]).toContain('[Vault] script loaded');
        expect(log.mock.calls[1]).toHaveLength(2);
        expect(log.mock.calls[2]).toHaveLength(2);
    });

    it('stays silent until debug is enabled, then formats objects as JSON', () => {
        const spies = {
            debug: vi.spyOn(console, 'debug').mockImplementation(() => undefined),
            info: vi.spyOn(console, 'info').mockImplementation(() => undefined),
            warn: vi.spyOn(console, 'warn').mockImplementation(() => undefined),
            error: vi.spyOn(console, 'error').mockImplementation(() => undefined),
        };
        const logger = createBundleLogger('Vault');

        logger.debug('a');
        logger.info('a');
        logger.warn('a');
        logger.error('a');
        for (const spy of Object.values(spies)) {
            expect(spy).not.toHaveBeenCalled();
        }

        logger.setDebug(true);
        const err = new Error('boom');
        logger.debug({ a: 1 }, 'text', null);
        logger.info('info');
        logger.warn('warn');
        logger.error(err);

        expect(spies.debug.mock.calls[0].slice(2)).toEqual(['{"a":1}', 'text', null]);
        expect(spies.debug.mock.calls[0][0]).toContain('[Vault]');
        expect(spies.info.mock.calls[0].slice(2)).toEqual(['info']);
        expect(spies.warn.mock.calls[0].slice(2)).toEqual(['warn']);
        expect(spies.error.mock.calls[0][2]).toBe(err);

        logger.setDebug(false);
        logger.info('hidden');
        expect(spies.info).toHaveBeenCalledTimes(1);
    });
});

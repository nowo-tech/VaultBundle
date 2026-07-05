import type { SessionConfig } from './types.js';

export function isSessionValid(config: SessionConfig): boolean {
    if (!config.token) {
        return false;
    }

    if (!config.expiresAt) {
        return true;
    }

    return new Date(config.expiresAt).getTime() > Date.now();
}

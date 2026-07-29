/**
 * Console logger helpers for Vault Bundle frontend assets (debug-gated).
 */

/** Options for {@link createBundleLogger}. */
export type BundleLoggerOptions = {
    /** Optional build timestamp shown on script-loaded log. */
    buildTime?: string;
};

/** Structured console logger used by Vault UI scripts. */
export type BundleLogger = {
    scriptLoaded: () => void;
    setDebug: (enabled: boolean) => void;
    debug: (...args: unknown[]) => void;
    info: (...args: unknown[]) => void;
    warn: (...args: unknown[]) => void;
    error: (...args: unknown[]) => void;
};

const STYLES = {
    script: 'color:#0ea5e9;font-weight:bold',
    debug: 'color:#6b7280',
    info: 'color:#2563eb',
    warn: 'color:#d97706',
    error: 'color:#dc2626;font-weight:bold',
} as const;

const EMOJI = {
    script: '📦',
    debug: '🔍',
    info: 'ℹ️',
    warn: '⚠️',
    error: '❌',
} as const;

/** Serialize plain objects for console output. */
function formatArgs(args: unknown[]): unknown[] {
    return args.map((arg) =>
        typeof arg === 'object' && arg !== null && !(arg instanceof Error) ? JSON.stringify(arg) : arg,
    );
}

/**
 * Create a named, debug-gated console logger for bundle scripts.
 *
 * @param name - Logger name prefix (e.g. `Vault`)
 * @param options - Optional build metadata
 */
export function createBundleLogger(name: string, options: BundleLoggerOptions = {}): BundleLogger {
    const prefix = `[${name}]`;
    const { buildTime } = options;
    let debugEnabled = false;

    return {
        scriptLoaded(): void {
            if (buildTime !== undefined && buildTime !== '') {
                console.log(
                    `%c${EMOJI.script} ${prefix} script loaded, build time: %c${buildTime}`,
                    STYLES.script,
                    'color:#059669',
                );
            } else {
                console.log(`%c${EMOJI.script} ${prefix} script loaded`, STYLES.script);
            }
        },
        setDebug(enabled: boolean): void {
            debugEnabled = enabled;
        },
        debug(...args: unknown[]): void {
            if (!debugEnabled) {
                return;
            }

            console.debug(`%c${EMOJI.debug} ${prefix}`, STYLES.debug, ...formatArgs(args));
        },
        info(...args: unknown[]): void {
            if (!debugEnabled) {
                return;
            }

            console.info(`%c${EMOJI.info} ${prefix}`, STYLES.info, ...formatArgs(args));
        },
        warn(...args: unknown[]): void {
            if (!debugEnabled) {
                return;
            }

            console.warn(`%c${EMOJI.warn} ${prefix}`, STYLES.warn, ...formatArgs(args));
        },
        error(...args: unknown[]): void {
            if (!debugEnabled) {
                return;
            }

            console.error(`%c${EMOJI.error} ${prefix}`, STYLES.error, ...formatArgs(args));
        },
    };
}

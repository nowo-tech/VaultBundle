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

/** True when the page asked for a silent console (`<html data-nowo-console="quiet">` or `globalThis.NOWO_CONSOLE = 'quiet'`). */
function isQuiet(): boolean {
  try {
    if ((globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE === 'quiet') return true;
    return typeof document !== 'undefined' && document.documentElement.dataset.nowoConsole === 'quiet';
  } catch {
    return false;
  }
}

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
            if (isQuiet()) return;
            if (alreadyAnnounced(name)) return;
            if (buildTime !== undefined && buildTime !== '') {
                console.log(
                    `%c${EMOJI.script} ${prefix} script loaded, build: %c${buildTime}`,
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

/**
 * Records `name` in a page-wide `Set` on `globalThis` and tells whether it was announced before (REQ-ASSETS-005): a
 * script that is evaluated twice (Turbo, a fragment, two copies, two loggers with the same name) prints its
 * "script loaded" line once per page. Page HTML that clobbers the global (DOM named access) is replaced by a fresh set.
 *
 * @param name - Logger name in kebab-case, e.g. `icon-selector` or `ui-kit/dialog`.
 * @returns True when the line was already printed on this page.
 */
function alreadyAnnounced(name: string): boolean {
    const host = globalThis as { __nowoScriptBannerNames?: unknown };
    if (!(host.__nowoScriptBannerNames instanceof Set)) {
        host.__nowoScriptBannerNames = new Set<string>();
    }
    const seen = host.__nowoScriptBannerNames as Set<string>;
    if (seen.has(name)) {
        return true;
    }
    seen.add(name);

    return false;
}

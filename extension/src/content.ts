import {
    debounce,
    fillCredentials,
    findLoginFields,
    removeAutofillPanel,
    renderAutofillPanel,
} from './login-fields.js';
import { ext } from './runtime.js';
import type { VaultLoginSummary } from './types.js';

// The page URL is taken by the background from the sender tab, never from this (page-influenced) script.
function scanPage(): void {
    const fields = findLoginFields(document);
    if (!fields) {
        removeAutofillPanel();
        return;
    }

    ext.runtime.sendMessage(
        { type: 'vault:getLogins' },
        (response: { ok?: boolean; logins?: VaultLoginSummary[] }) => {
            if (ext.runtime.lastError || !response?.ok) {
                removeAutofillPanel();
                return;
            }

            renderAutofillPanel(document, response.logins ?? [], fields, (login) => {
                // Only after a trusted click: ask for this login's credentials.
                ext.runtime.sendMessage(
                    { type: 'vault:fill', id: login.id },
                    (fill: { ok?: boolean; username?: string; password?: string }) => {
                        if (ext.runtime.lastError || !fill?.ok) {
                            return;
                        }
                        fillCredentials({ username: fill.username ?? '', password: fill.password ?? '' }, fields);
                    },
                );
            });
        },
    );
}

const scanSoon = debounce(scanPage, 300);
const observer = new MutationObserver(() => scanSoon());
observer.observe(document.documentElement, { childList: true, subtree: true });
scanPage();

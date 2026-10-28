export interface SessionConfig {
    baseUrl: string;
    token: string;
    expiresAt: string;
}

export interface VaultLoginMatch {
    id: string;
    title: string;
    username: string;
    password: string;
}

/** What the content script (page context) receives: never the password (SEC-004 H1). */
export interface VaultLoginSummary {
    id: string;
    title: string;
    username: string;
}

export interface VaultProfile {
    identifier?: string;
    userId?: string;
}

export interface LoginTokenResponse {
    token: string;
    expiresAt: string;
}

export interface LoginsResponse {
    logins?: VaultLoginMatch[];
}

export interface ApiErrorPayload {
    error?: string;
    raw?: string;
}

export interface LoginFields {
    usernameField: HTMLInputElement | null;
    passwordField: HTMLInputElement;
}

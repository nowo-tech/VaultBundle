export interface SessionConfig {
    baseUrl: string;
    token: string;
    expiresAt: string;
}

export interface VaultLoginMatch {
    title: string;
    username: string;
    password: string;
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

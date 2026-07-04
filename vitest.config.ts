import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['src/Resources/assets/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'text-summary', 'html'],
            reportsDirectory: './coverage-ts',
            include: ['src/Resources/assets/src/vault-password-client.ts'],
            exclude: ['**/*.test.ts', '**/node_modules/**'],
        },
    },
});

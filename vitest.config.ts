import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        globals: true,
        include: [
            'src/Resources/assets/**/*.test.ts',
            'extension/src/**/*.test.ts',
        ],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'text-summary', 'html', 'json-summary'],
            reportsDirectory: './coverage-ts',
            // Every shipped TS source (manage UI + browser extension); only tests are excluded (REQ-TEST-003).
            include: [
                'src/Resources/assets/src/**/*.ts',
                'extension/src/**/*.ts',
            ],
            exclude: [
                '**/*.test.ts',
                '**/node_modules/**',
            ],
            thresholds: {
                // Measured 2026-10-29: lines 99.69, statements 99.69, functions 98.68, branches 95.28.
                lines: 98,
                statements: 98,
                functions: 95,
                branches: 90,
            },
        },
    },
});
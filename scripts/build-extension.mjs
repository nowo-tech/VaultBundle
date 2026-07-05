import { resolve } from 'node:path';
import { build } from 'vite';

const root = resolve(import.meta.dirname, '..');
const srcDir = resolve(root, 'extension/src');
const outDir = resolve(root, 'extension/build');

const moduleEntries = ['background', 'popup', 'options'];

for (const name of moduleEntries) {
    await build({
        configFile: false,
        build: {
            outDir,
            emptyOutDir: name === 'background',
            target: 'es2022',
            minify: false,
            rollupOptions: {
                input: resolve(srcDir, `${name}.ts`),
                output: {
                    format: 'es',
                    entryFileNames: `${name}.js`,
                },
            },
        },
    });
}

await build({
    configFile: false,
    build: {
        outDir,
        emptyOutDir: false,
        target: 'es2022',
        minify: false,
        lib: {
            entry: resolve(srcDir, 'content.ts'),
            name: 'NowoVaultContent',
            formats: ['iife'],
            fileName: () => 'content.js',
        },
    },
});

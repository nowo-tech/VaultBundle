import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        outDir: 'src/Resources/public',
        emptyOutDir: false,
        rollupOptions: {
            input: 'src/Resources/assets/src/vault.ts',
            output: {
                format: 'es',
                entryFileNames: 'vault.js',
                assetFileNames: 'vault.[ext]',
            },
        },
        minify: true,
        sourcemap: false,
    },
});

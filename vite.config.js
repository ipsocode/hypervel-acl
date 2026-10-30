import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// Builds the bundled UI into dist/, committed like Telescope's. The names carry no hash:
// the Blade shell versions them with a hash of the file.
export default defineConfig(({ command }) => ({
    plugins: [react(), tailwindcss()],
    test: { environment: 'jsdom', include: ['resources/js/**/*.test.{ts,tsx}'] },
    define: command === 'build' ? { 'process.env.NODE_ENV': JSON.stringify('production') } : {},
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        cssCodeSplit: false,
        lib: false,
        rollupOptions: {
            input: 'resources/js/app.tsx',
            output: {
                entryFileNames: 'app.js',
                assetFileNames: 'app[extname]',
            },
        },
    },
}));

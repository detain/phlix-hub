import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'node:path';

export default defineConfig({
    plugins: [vue()],
    base: '/assets/app/',
    build: {
        outDir: resolve(__dirname, '../public/assets/app'),
        emptyOutDir: true,
        sourcemap: true,
        manifest: true,
    },
});

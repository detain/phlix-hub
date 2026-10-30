import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'node:path';

export default defineConfig({
    plugins: [vue()],
    base: '/assets/app/',
    build: {
        // M-HUB-10: the browser floor is DECLARED, not inherited from a
        // default that a Vite upgrade may silently move. Vite 8.1.5's default
        // happened to be this value; pinning it makes floor drift a deliberate
        // edit with a line to review. `baseline-widely-available` ≈ Chrome
        // 107+ / Edge 107+ / Safari 16+ / Firefox 104+ — optional chaining,
        // nullish coalescing and logical assignment ship in the dist output.
        // The hub portal is a desktop/mobile-browser surface (TV clients are
        // separate packaged apps); a below-floor browser gets the fallback
        // notice in the served index shell, not a silent white screen.
        target: 'baseline-widely-available',
        outDir: resolve(__dirname, '../public/assets/app'),
        emptyOutDir: true,
        sourcemap: true,
        manifest: true,
    },
});

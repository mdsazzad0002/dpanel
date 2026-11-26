import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

// A new id per build, so anything the client cached from the previous build
// (the translation bundles in IndexedDB) is dropped rather than served stale.
const buildId = process.env.DPANEL_BUILD_ID || String(Date.now());

export default defineConfig({
    define: {
        __DPANEL_BUILD_ID__: JSON.stringify(buildId),
    },
    server: {
        watch: {
            ignored: [
                '**/vendor/**',
                '**/node_modules/**',
                '**/.git/**',
                '**/storage/**',
            ],
        },
    },
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: [
                'app/**',
                'resources/**',
                'routes/**',
                'config/**',
                'lang/**',
            ],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});

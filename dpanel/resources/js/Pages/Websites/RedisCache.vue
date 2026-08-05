<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    redisCache: {
        type: Object,
        default: () => ({
            prefix: '',
            host: '127.0.0.1',
            port: 6379,
            database: 0,
            connection: 'website_cache',
            connected: false,
            error: null,
            key_count: 0,
            sample_keys: [],
        }),
    },
    application: {
        type: Object,
        default: () => ({ type: 'unknown', label: 'Application not detected', root: '', config_file: null, detected: false }),
    },
    revisions: { type: Array, default: () => [] },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const clearForm = useForm({});
const configureForm = useForm({});
const copied = ref('');
const guideOpen = ref(false);
const showLaravel = computed(() => props.application.type !== 'wordpress');
const showWordPress = computed(() => props.application.type !== 'laravel');

const laravelEnv = computed(() => `CACHE_STORE=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=${props.redisCache.host}
REDIS_PORT=${props.redisCache.port}
REDIS_PASSWORD=null
REDIS_CACHE_DB=${props.redisCache.database}
REDIS_CACHE_CONNECTION=cache
CACHE_PREFIX=${props.redisCache.prefix}
REDIS_PREFIX=${props.redisCache.prefix}`);

const laravelCommands = `php artisan optimize:clear
php artisan config:cache
php artisan cache:put redis_test "Redis is working" 60
php artisan cache:get redis_test`;

const predisFallback = `composer require predis/predis
# Then change this line in .env:
REDIS_CLIENT=predis

php artisan optimize:clear
php artisan config:cache`;

const wordpressConfig = computed(() => `define('WP_REDIS_HOST', '${props.redisCache.host}');
define('WP_REDIS_PORT', ${props.redisCache.port});
define('WP_REDIS_DATABASE', ${props.redisCache.database});
define('WP_REDIS_PREFIX', '${props.redisCache.prefix}');
define('WP_REDIS_TIMEOUT', 1);
define('WP_REDIS_READ_TIMEOUT', 1);`);

const copyText = async (label, value) => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = label;
        window.setTimeout(() => {
            if (copied.value === label) copied.value = '';
        }, 2000);
    } catch {
        copied.value = '';
    }
};

const clearWebsiteCache = () => {
    if (!confirm(`Clear Redis keys for ${props.website.domain}?`)) return;
    clearForm.post(panelRoute('websites.redis-cache.clear', { id: props.website.id }));
};
const applyConfiguration = () => {
    if (confirm(`Back up and update ${props.application.config_file}?`)) configureForm.post(panelRoute('websites.redis-cache.configure', { id: props.website.id }));
};
const rollbackRevision = (revision) => {
    if (confirm('Restore this configuration backup?')) configureForm.post(panelRoute('websites.redis-cache.rollback', { id: props.website.id, revision: revision.id }));
};
</script>

<template>
    <Head :title="`Redis Cache - ${website.domain}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Redis Cache Setup Guide</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Configure a fast, isolated cache for {{ website.domain }}.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="guideOpen = true">
                    Setup Guide
                </button>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to Website Management
                </Link>
            </div>

            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Detected application</p>
                        <div class="mt-2 flex items-center gap-2">
                            <span :class="application.detected ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-200'" class="rounded-full px-3 py-1 text-sm font-semibold">
                                {{ application.label }}
                            </span>
                            <span class="text-xs text-slate-500">{{ application.detected ? 'Auto-detected' : 'Choose a guide below' }}</span>
                        </div>
                    </div>
                    <div class="min-w-0 text-sm">
                        <p class="text-xs text-slate-500">Project root</p>
                        <code class="mt-1 block max-w-xl truncate rounded bg-slate-100 px-3 py-2 dark:bg-slate-800">{{ application.root || 'Not available' }}</code>
                    </div>
                </div>
                <div v-if="application.config_file" class="mt-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                    Update this file: <code class="font-semibold">{{ application.config_file }}</code>. Create a backup before editing.
                    <button type="button" :disabled="configureForm.processing" class="mt-3 block rounded bg-blue-600 px-4 py-2 font-medium text-white disabled:opacity-50" @click="applyConfiguration">Apply Redis Configuration Automatically</button>
                </div>
                <div v-else class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    Laravel or WordPress files were not found. Confirm the project root, then follow the matching manual guide.
                </div>
            </section>

            <section v-if="revisions.length" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">Configuration revision history</h2>
                <div class="mt-3 divide-y divide-slate-200 dark:divide-slate-800">
                    <div v-for="revision in revisions" :key="revision.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        <div><strong>{{ revision.framework }}</strong><p class="text-xs text-slate-500">{{ revision.created_at }} · {{ revision.status }}</p></div>
                        <button v-if="revision.status === 'applied'" type="button" :disabled="configureForm.processing" class="rounded border border-amber-400 px-3 py-1.5 text-amber-700 disabled:opacity-50" @click="rollbackRevision(revision)">Rollback</button>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div :class="redisCache.connected ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mb-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ redisCache.connected ? 'Redis connected' : 'Redis unavailable' }}</strong>
                    <span v-if="redisCache.error"> — {{ redisCache.error }}</span>
                </div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Namespace</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Redis Prefix</p>
                        <p class="mt-1 break-all rounded-md bg-slate-100 px-3 py-2 text-sm dark:bg-slate-800">{{ redisCache.prefix }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Connection</p>
                        <p class="mt-1 rounded-md bg-slate-100 px-3 py-2 text-sm dark:bg-slate-800">
                            {{ redisCache.host }}:{{ redisCache.port }} / DB {{ redisCache.database }} ({{ redisCache.connection }})
                        </p>
                    </div>
                </div>

                <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                    Use this exact prefix in each website `.env` to avoid cross-website cache conflicts:
                    <div class="mt-2 font-mono">CACHE_PREFIX={{ redisCache.prefix }}</div>
                    <div class="font-mono">REDIS_PREFIX={{ redisCache.prefix }}</div>
                </div>
            </section>

            <div v-if="guideOpen" class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="Redis setup guide">
                <button type="button" class="absolute inset-0 bg-slate-950/60" aria-label="Close setup guide" @click="guideOpen = false"></button>
                <aside class="relative flex h-full w-full max-w-3xl flex-col bg-slate-50 shadow-2xl dark:bg-slate-950">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
                        <div><h2 class="text-lg font-semibold">Redis Setup Guide</h2><p class="text-xs text-slate-500">{{ application.label }} · {{ website.domain }}</p></div>
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="guideOpen = false">Close</button>
                    </div>
                    <div class="flex-1 space-y-4 overflow-y-auto p-4 sm:p-6">
            <section v-if="showLaravel" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">Recommended</p>
                        <h2 class="mt-1 text-lg font-semibold">Laravel setup</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Complete these steps from the website project root.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800">About 2 minutes</span>
                </div>

                <ol class="mt-5 space-y-5">
                    <li class="grid gap-3 sm:grid-cols-[2rem_1fr]">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">1</span>
                        <div>
                            <h3 class="font-medium">Update the website <code>.env</code></h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Replace existing Redis and cache lines with this website-specific configuration.</p>
                            <div class="relative mt-3 overflow-hidden rounded-lg bg-slate-950 text-slate-100">
                                <button type="button" class="absolute right-3 top-3 rounded bg-slate-700 px-2.5 py-1 text-xs hover:bg-slate-600" @click="copyText('laravel', laravelEnv)">
                                    {{ copied === 'laravel' ? 'Copied!' : 'Copy' }}
                                </button>
                                <pre class="overflow-x-auto p-4 pr-20 text-xs leading-6"><code>{{ laravelEnv }}</code></pre>
                            </div>
                        </div>
                    </li>
                    <li class="grid gap-3 sm:grid-cols-[2rem_1fr]">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">2</span>
                        <div>
                            <h3 class="font-medium">Clear old configuration and verify Redis</h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Run each command in order. The final command should print <strong>Redis is working</strong>.</p>
                            <div class="relative mt-3 overflow-hidden rounded-lg bg-slate-950 text-slate-100">
                                <button type="button" class="absolute right-3 top-3 rounded bg-slate-700 px-2.5 py-1 text-xs hover:bg-slate-600" @click="copyText('commands', laravelCommands)">
                                    {{ copied === 'commands' ? 'Copied!' : 'Copy' }}
                                </button>
                                <pre class="overflow-x-auto p-4 pr-20 text-xs leading-6"><code>{{ laravelCommands }}</code></pre>
                            </div>
                        </div>
                    </li>
                    <li class="grid gap-3 sm:grid-cols-[2rem_1fr]">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">3</span>
                        <div>
                            <h3 class="font-medium">If “Driver [phpredis] not supported” appears</h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The PHP Redis extension is not enabled for this website’s PHP version. Ask the administrator to enable <code>php{{ website.php_version }}-redis</code>, or use this Composer fallback:</p>
                            <div class="relative mt-3 overflow-hidden rounded-lg bg-slate-950 text-slate-100">
                                <button type="button" class="absolute right-3 top-3 rounded bg-slate-700 px-2.5 py-1 text-xs hover:bg-slate-600" @click="copyText('predis', predisFallback)">
                                    {{ copied === 'predis' ? 'Copied!' : 'Copy' }}
                                </button>
                                <pre class="overflow-x-auto p-4 pr-20 text-xs leading-6"><code>{{ predisFallback }}</code></pre>
                            </div>
                        </div>
                    </li>
                    <li class="grid gap-3 sm:grid-cols-[2rem_1fr]">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">4</span>
                        <div>
                            <h3 class="font-medium">Confirm on this page</h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Refresh this page. The key count should increase and the test key should appear below.</p>
                        </div>
                    </li>
                </ol>
            </section>

            <section v-if="showWordPress" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-lg font-semibold">WordPress setup</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Use these steps only for a WordPress website.</p>
                <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm">
                    <li>Install and activate the <strong>Redis Object Cache</strong> plugin from WordPress Plugins.</li>
                    <li>Add the configuration below to <code>wp-config.php</code>, immediately above the “stop editing” line.</li>
                    <li>Open <strong>Settings → Redis</strong> and click <strong>Enable Object Cache</strong>.</li>
                </ol>
                <div class="relative mt-4 overflow-hidden rounded-lg bg-slate-950 text-slate-100">
                    <button type="button" class="absolute right-3 top-3 rounded bg-slate-700 px-2.5 py-1 text-xs hover:bg-slate-600" @click="copyText('wordpress', wordpressConfig)">
                        {{ copied === 'wordpress' ? 'Copied!' : 'Copy' }}
                    </button>
                    <pre class="overflow-x-auto p-4 pr-20 text-xs leading-6"><code>{{ wordpressConfig }}</code></pre>
                </div>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">Before you finish</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                        <li>✓ Use the exact prefix shown above for this website only.</li>
                        <li>✓ Keep Redis on <code>127.0.0.1</code>; do not expose port 6379 publicly.</li>
                        <li>✓ Never use <code>FLUSHDB</code> or <code>FLUSHALL</code> on a shared server.</li>
                        <li>✓ Clear application config after changing <code>.env</code>.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950">
                    <h2 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h2>
                    <ul class="mt-3 space-y-2 text-sm text-amber-800 dark:text-amber-200">
                        <li><strong>Connection refused:</strong> ask the server administrator to start Redis.</li>
                        <li><strong>Class Redis not found:</strong> enable the PHP Redis extension for this website’s PHP version.</li>
                        <li><strong>No keys shown:</strong> generate a cache entry, then refresh this page.</li>
                        <li><strong>Old values:</strong> use “Clear Website Cache” below; it deletes only this website’s namespace.</li>
                    </ul>
                </div>
            </section>
                    </div>
                </aside>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Verify &amp; manage cache keys</h2>
                <div class="mt-3 flex items-center justify-between gap-3">
                    <p class="text-sm">Total keys with this prefix: <strong>{{ redisCache.key_count }}</strong></p>
                    <button
                        type="button"
                        :disabled="clearForm.processing || !redisCache.connected"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                        @click="clearWebsiteCache"
                    >
                        Clear Website Cache
                    </button>
                </div>

                <div class="mt-4 overflow-x-auto rounded-md border border-slate-200 dark:border-slate-800">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">Sample Keys (max 25)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="key in redisCache.sample_keys" :key="key" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2 font-mono text-xs">{{ key }}</td>
                            </tr>
                            <tr v-if="redisCache.sample_keys.length === 0">
                                <td class="px-3 py-4 text-center text-slate-500">No keys found for this website prefix.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

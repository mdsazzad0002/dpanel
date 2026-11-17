<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
const showLaravel = computed(() => ['laravel', 'unknown'].includes(props.application.type));
const showWordPress = computed(() => ['wordpress', 'unknown'].includes(props.application.type));
const showCodeIgniter = computed(() => ['codeigniter4', 'unknown'].includes(props.application.type));

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

const codeIgniterEnv = computed(() => `cache.handler = redis
cache.backupHandler = file
cache.redis.host = ${props.redisCache.host}
cache.redis.port = ${props.redisCache.port}
cache.redis.database = ${props.redisCache.database}
cache.redis.prefix = ${props.redisCache.prefix}`);

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
    if (!confirm(t('websites.redisCache.clearConfirm', { domain: props.website.domain }))) return;
    clearForm.post(panelRoute('websites.redis-cache.clear', { id: props.website.id }));
};
const applyConfiguration = () => {
    if (confirm(t('websites.redisCache.applyConfirm', { file: props.application.config_file }))) configureForm.post(panelRoute('websites.redis-cache.configure', { id: props.website.id }));
};
const rollbackRevision = (revision) => {
    if (confirm(t('websites.redisCache.rollbackConfirm'))) configureForm.post(panelRoute('websites.redis-cache.rollback', { id: props.website.id, revision: revision.id }));
};
</script>

<template>
    <Head :title="t('websites.redisCache.headTitle', { domain: website.domain })" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('websites.redisCache.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('websites.redisCache.subtitle', { domain: website.domain }) }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="guideOpen = true">
                    {{ t('websites.redisCache.guide') }}
                </button>
                <Link :href="panelRoute('websites.redis-explorer.index', { id: website.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    {{ t('websites.redisCache.openExplorer') }}
                </Link>
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                    {{ t('websites.aliasApi.back') }}
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
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('websites.redisCache.detected') }}</p>
                        <div class="mt-2 flex items-center gap-2">
                            <span :class="application.detected ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-200'" class="rounded-full px-3 py-1 text-sm font-semibold">
                                {{ application.label }}
                            </span>
                            <span class="text-xs text-slate-500">{{ application.detected ? t('websites.redisCache.autoDetected') : t('websites.redisCache.chooseGuide') }}</span>
                        </div>
                    </div>
                    <div class="min-w-0 text-sm">
                        <p class="text-xs text-slate-500">{{ t('websites.redisCache.root') }}</p>
                        <code class="mt-1 block max-w-xl truncate rounded bg-slate-100 px-3 py-2 dark:bg-slate-800">{{ application.root || t('websites.redisCache.notAvailable') }}</code>
                    </div>
                </div>
                <div v-if="application.config_file" class="mt-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                    <i18n-t keypath="websites.redisCache.updateFile" scope="global"><template #file><code class="font-semibold">{{ application.config_file }}</code></template></i18n-t>
                    <button type="button" :disabled="configureForm.processing" class="mt-3 block rounded bg-blue-600 px-4 py-2 font-medium text-white disabled:opacity-50" @click="applyConfiguration">{{ t('websites.redisCache.applyAuto') }}</button>
                </div>
                <div v-else class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    {{ t('websites.redisCache.notFound') }}
                </div>
            </section>

            <section v-if="revisions.length" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="font-semibold">{{ t('websites.redisCache.history') }}</h2>
                <div class="mt-3 divide-y divide-slate-200 dark:divide-slate-800">
                    <div v-for="revision in revisions" :key="revision.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        <div><strong>{{ revision.framework }}</strong><p class="text-xs text-slate-500">{{ revision.created_at }} · {{ revision.status }}</p></div>
                        <button v-if="revision.status === 'applied'" type="button" :disabled="configureForm.processing" class="rounded border border-amber-400 px-3 py-1.5 text-amber-700 disabled:opacity-50" @click="rollbackRevision(revision)">{{ t('websites.redisCache.rollback') }}</button>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div :class="redisCache.connected ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="mb-4 rounded-md border px-4 py-3 text-sm">
                    <strong>{{ redisCache.connected ? t('websites.redisCache.connected') : t('websites.redisCache.unavailable') }}</strong>
                    <span v-if="redisCache.error"> — {{ redisCache.error }}</span>
                </div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('websites.redisCache.namespace') }}</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('websites.redisCache.prefix') }}</p>
                        <p class="mt-1 break-all rounded-md bg-slate-100 px-3 py-2 text-sm dark:bg-slate-800">{{ redisCache.prefix }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('websites.redisCache.connection') }}</p>
                        <p class="mt-1 rounded-md bg-slate-100 px-3 py-2 text-sm dark:bg-slate-800">
                            {{ redisCache.host }}:{{ redisCache.port }} / DB {{ redisCache.database }} ({{ redisCache.connection }})
                        </p>
                    </div>
                </div>

                <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                    {{ t('websites.redisCache.prefixHelp') }}
                    <div class="mt-2 font-mono">CACHE_PREFIX={{ redisCache.prefix }}</div>
                    <div class="font-mono">REDIS_PREFIX={{ redisCache.prefix }}</div>
                </div>
            </section>

            <div v-if="guideOpen" class="fixed inset-0 z-50 flex justify-end top-0" style="margin-top: 0;" role="dialog" aria-modal="true" :aria-label="t('websites.redisCache.guideLabel')">
                <button type="button" class="absolute inset-0 bg-slate-950/60" :aria-label="t('websites.redisCache.closeGuide')" @click="guideOpen = false"></button>
                <aside class="relative flex h-full w-full max-w-3xl flex-col bg-slate-50 shadow-2xl dark:bg-slate-950 border dark:border-slate-800">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
                        <div><h2 class="text-lg font-semibold">{{ t('websites.redisCache.guideTitle') }}</h2><p class="text-xs text-slate-500">{{ application.label }} · {{ website.domain }}</p></div>
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="guideOpen = false">{{ t('common.close') }}</button>
                    </div>
                    <div class="flex-1 space-y-4 overflow-y-auto p-4 sm:p-6">
            <section v-if="showLaravel" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">{{ t('websites.redisCache.recommended') }}</p>
                        <h2 class="mt-1 text-lg font-semibold">{{ t('websites.redisCache.laravel.title') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('websites.redisCache.laravel.help') }}</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800">{{ t('websites.redisCache.laravel.time') }}</span>
                </div>

                <ol class="mt-5 space-y-5">
                    <li class="grid gap-3 sm:grid-cols-[2rem_1fr]">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">1</span>
                        <div>
                            <h3 class="font-medium"><i18n-t keypath="websites.redisCache.laravel.step1" scope="global"><template #file><code>.env</code></template></i18n-t></h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('websites.redisCache.laravel.step1Help') }}</p>
                            <div class="relative mt-3 overflow-hidden rounded-lg bg-slate-950 text-slate-100">
                                <button type="button" class="absolute right-3 top-3 rounded bg-slate-700 px-2.5 py-1 text-xs hover:bg-slate-600" @click="copyText('laravel', laravelEnv)">
                                    {{ copied === 'laravel' ? t('websites.redisCache.copied') : t('websites.redisCache.copy') }}
                                </button>
                                <pre class="overflow-x-auto p-4 pr-20 text-xs leading-6"><code>{{ laravelEnv }}</code></pre>
                            </div>
                        </div>
                    </li>
                    <li class="grid gap-3 sm:grid-cols-[2rem_1fr]">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">2</span>
                        <div>
                            <h3 class="font-medium">{{ t('websites.redisCache.laravel.step2') }}</h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><i18n-t keypath="websites.redisCache.laravel.step2Help" scope="global"><template #text><strong>Redis is working</strong></template></i18n-t></p>
                            <div class="relative mt-3 overflow-hidden rounded-lg bg-slate-950 text-slate-100">
                                <button type="button" class="absolute right-3 top-3 rounded bg-slate-700 px-2.5 py-1 text-xs hover:bg-slate-600" @click="copyText('commands', laravelCommands)">
                                    {{ copied === 'commands' ? t('websites.redisCache.copied') : t('websites.redisCache.copy') }}
                                </button>
                                <pre class="overflow-x-auto p-4 pr-20 text-xs leading-6"><code>{{ laravelCommands }}</code></pre>
                            </div>
                        </div>
                    </li>
                    <li class="grid gap-3 sm:grid-cols-[2rem_1fr]">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">3</span>
                        <div>
                            <h3 class="font-medium">{{ t('websites.redisCache.laravel.step3') }}</h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><i18n-t keypath="websites.redisCache.laravel.step3Help" scope="global"><template #package><code>php{{ website.php_version }}-redis</code></template></i18n-t></p>
                            <div class="relative mt-3 overflow-hidden rounded-lg bg-slate-950 text-slate-100">
                                <button type="button" class="absolute right-3 top-3 rounded bg-slate-700 px-2.5 py-1 text-xs hover:bg-slate-600" @click="copyText('predis', predisFallback)">
                                    {{ copied === 'predis' ? t('websites.redisCache.copied') : t('websites.redisCache.copy') }}
                                </button>
                                <pre class="overflow-x-auto p-4 pr-20 text-xs leading-6"><code>{{ predisFallback }}</code></pre>
                            </div>
                        </div>
                    </li>
                    <li class="grid gap-3 sm:grid-cols-[2rem_1fr]">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">4</span>
                        <div>
                            <h3 class="font-medium">{{ t('websites.redisCache.laravel.step4') }}</h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('websites.redisCache.laravel.step4Help') }}</p>
                        </div>
                    </li>
                </ol>
            </section>

            <section v-if="showCodeIgniter" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-lg font-semibold">{{ t('websites.redisCache.ci.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><i18n-t keypath="websites.redisCache.ci.help" scope="global"><template #file><code>.env</code></template></i18n-t></p>
                <div class="relative mt-4 overflow-hidden rounded-lg bg-slate-950 text-slate-100">
                    <button type="button" class="absolute right-3 top-3 rounded bg-slate-700 px-2.5 py-1 text-xs hover:bg-slate-600" @click="copyText('codeigniter', codeIgniterEnv)">
                        {{ copied === 'codeigniter' ? t('websites.redisCache.copied') : t('websites.redisCache.copy') }}
                    </button>
                    <pre class="overflow-x-auto p-4 pr-20 text-xs leading-6"><code>{{ codeIgniterEnv }}</code></pre>
                </div>
            </section>

            <section v-if="showWordPress" class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-lg font-semibold">{{ t('websites.redisCache.wp.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('websites.redisCache.wp.help') }}</p>
                <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm">
                    <li><i18n-t keypath="websites.redisCache.wp.step1" scope="global"><template #plugin><strong>Redis Object Cache</strong></template></i18n-t></li>
                    <li><i18n-t keypath="websites.redisCache.wp.step2" scope="global"><template #file><code>wp-config.php</code></template></i18n-t></li>
                    <li><i18n-t keypath="websites.redisCache.wp.step3" scope="global"><template #menu><strong>Settings → Redis</strong></template><template #button><strong>Enable Object Cache</strong></template></i18n-t></li>
                </ol>
                <div class="relative mt-4 overflow-hidden rounded-lg bg-slate-950 text-slate-100">
                    <button type="button" class="absolute right-3 top-3 rounded bg-slate-700 px-2.5 py-1 text-xs hover:bg-slate-600" @click="copyText('wordpress', wordpressConfig)">
                        {{ copied === 'wordpress' ? t('websites.redisCache.copied') : t('websites.redisCache.copy') }}
                    </button>
                    <pre class="overflow-x-auto p-4 pr-20 text-xs leading-6"><code>{{ wordpressConfig }}</code></pre>
                </div>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="font-semibold">{{ t('websites.redisCache.finish.title') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                        <li>✓ {{ t('websites.redisCache.finish.prefix') }}</li>
                        <li>✓ <i18n-t keypath="websites.redisCache.finish.local" scope="global"><template #host><code>127.0.0.1</code></template></i18n-t></li>
                        <li>✓ <i18n-t keypath="websites.redisCache.finish.flush" scope="global"><template #a><code>FLUSHDB</code></template><template #b><code>FLUSHALL</code></template></i18n-t></li>
                        <li>✓ <i18n-t keypath="websites.redisCache.finish.clear" scope="global"><template #file><code>.env</code></template></i18n-t></li>
                    </ul>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950">
                    <h2 class="font-semibold text-amber-900 dark:text-amber-100">{{ t('websites.redisCache.trouble.title') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm text-amber-800 dark:text-amber-200">
                        <li><strong>{{ t('websites.redisCache.trouble.refused') }}</strong> {{ t('websites.redisCache.trouble.refusedHelp') }}</li>
                        <li><strong>{{ t('websites.redisCache.trouble.class') }}</strong> {{ t('websites.redisCache.trouble.classHelp') }}</li>
                        <li><strong>{{ t('websites.redisCache.trouble.noKeys') }}</strong> {{ t('websites.redisCache.trouble.noKeysHelp') }}</li>
                        <li><strong>{{ t('websites.redisCache.trouble.old') }}</strong> {{ t('websites.redisCache.trouble.oldHelp') }}</li>
                    </ul>
                </div>
            </section>
                    </div>
                </aside>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('websites.redisCache.manage') }}</h2>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        <i18n-t keypath="websites.redisCache.inspect" scope="global"><template #link><Link :href="panelRoute('websites.redis-explorer.index', { id: website.id })" class="font-medium text-blue-600 hover:underline dark:text-blue-400">Redis Explorer</Link></template></i18n-t>
                    </p>
                    <button
                        type="button"
                        :disabled="clearForm.processing || !redisCache.connected"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                        @click="clearWebsiteCache"
                    >
                        {{ t('websites.redisCache.clear') }}
                    </button>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

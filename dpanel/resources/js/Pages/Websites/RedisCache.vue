<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

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
            key_count: 0,
            sample_keys: [],
        }),
    },
});

const page = usePage();
const clearForm = useForm({});

const clearWebsiteCache = () => {
    if (!confirm(`Clear Redis keys for ${props.website.domain}?`)) return;
    clearForm.post(route('websites.redis-cache.clear', props.website.id));
};
</script>

<template>
    <Head :title="`Redis Cache - ${website.domain}`" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Redis Cache Management</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Isolated cache namespace for {{ website.domain }}.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex justify-end gap-2">
                <Link :href="route('websites.manage', website.id)" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
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
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Namespace</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Redis Prefix</p>
                        <p class="mt-1 break-all rounded-md bg-slate-100 px-3 py-2 text-sm dark:bg-slate-800">{{ redisCache.prefix }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Connection</p>
                        <p class="mt-1 rounded-md bg-slate-100 px-3 py-2 text-sm dark:bg-slate-800">
                            {{ redisCache.host }}:{{ redisCache.port }} / DB {{ redisCache.database }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                    Use this exact prefix in each website `.env` to avoid cross-website cache conflicts:
                    <div class="mt-2 font-mono">CACHE_PREFIX={{ redisCache.prefix }}</div>
                    <div class="font-mono">REDIS_PREFIX={{ redisCache.prefix }}</div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Cache Keys</h2>
                <div class="mt-3 flex items-center justify-between gap-3">
                    <p class="text-sm">Total keys with this prefix: <strong>{{ redisCache.key_count }}</strong></p>
                    <button
                        type="button"
                        :disabled="clearForm.processing"
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


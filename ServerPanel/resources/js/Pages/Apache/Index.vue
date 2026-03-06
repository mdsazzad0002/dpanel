<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    apache: { type: Object, default: () => ({}) },
    websites: { type: Array, default: () => [] },
});

const page = usePage();

const actionForm = useForm({
    action: 'test',
});

const syncForm = useForm({});

const runAction = (action) => {
    actionForm.action = action;
    actionForm.post(route('apache.action'));
};

const syncSharedWebsites = () => {
    syncForm.post(route('apache.sync-shared-websites'));
};
</script>

<template>
    <Head title="Apache Manager" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Apache Manager</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Service controls, config validation, and shared website vhost generation.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 whitespace-pre-line">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 whitespace-pre-line">
                {{ page.props.flash.error }}
            </div>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">OS</p>
                    <p class="mt-2 text-2xl font-semibold">{{ apache.os_family || '-' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Service</p>
                    <p class="mt-2 text-2xl font-semibold">{{ apache.service_name || '-' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Status</p>
                    <p class="mt-2 text-2xl font-semibold">{{ apache.service_status || 'unknown' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Shared Config</p>
                    <p class="mt-2 text-2xl font-semibold">{{ apache.shared_vhost_exists ? 'Present' : 'Missing' }}</p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Apache Actions</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700" :disabled="actionForm.processing" @click="runAction('test')">Test Config</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('reload')">Reload</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('restart')">Restart</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('start')">Start</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('stop')">Stop</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :disabled="actionForm.processing" @click="runAction('status')">Status</button>
                    <button type="button" class="rounded-md border border-amber-300 px-3 py-2 text-sm text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-400" :disabled="actionForm.processing" @click="runAction('renew_ssl')">Renew SSL</button>
                </div>
                <p class="mt-3 break-all text-xs text-slate-500">Apache binary: {{ apache.httpd_path || '-' }}</p>
                <p class="mt-1 break-all text-xs text-slate-500">Main config: {{ apache.main_conf || '-' }}</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Shared Websites Config</h2>
                <p class="mt-2 break-all text-xs text-slate-500">Target file: {{ apache.shared_vhost_file || '-' }}</p>
                <p class="mt-1 text-xs text-slate-500">Last generated: {{ apache.shared_vhost_last_modified || '-' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ apache.include_hint }}</p>
                <div class="mt-3">
                    <button type="button" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700" :disabled="syncForm.processing" @click="syncSharedWebsites">
                        {{ syncForm.processing ? 'Syncing...' : 'Sync Shared Websites' }}
                    </button>
                </div>
            </section>

            <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Domain</th>
                            <th class="px-4 py-3">Root Path</th>
                            <th class="px-4 py-3">SSL</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="site in websites" :key="site.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ site.domain }}</td>
                            <td class="px-4 py-3 break-all font-mono text-xs">{{ site.root_path }}</td>
                            <td class="px-4 py-3">{{ site.enable_ssl ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3">{{ site.status }}</td>
                        </tr>
                        <tr v-if="websites.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-slate-500">No websites found for shared vhost generation.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

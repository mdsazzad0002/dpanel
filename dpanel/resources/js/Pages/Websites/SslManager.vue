<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    website: {
        type: Object,
        required: true,
    },
    sslStatus: {
        type: Object,
        default: () => ({}),
    },
    autoRenewNotice: {
        type: String,
        default: '',
    },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const csrfToken = computed(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
const syncMessage = ref('');
const syncMessageType = ref('success');
const syncLoading = ref(false);

const formatDate = (value) => {
    if (!value) return '-';
    try {
        return new Date(value).toLocaleString();
    } catch (error) {
        return String(value);
    }
};

const statusValue = computed(() => String(props.sslStatus?.status || 'unknown').toLowerCase());
const statusLabel = computed(() => {
    const value = statusValue.value;
    if (!value) return 'Unknown';
    return value.charAt(0).toUpperCase() + value.slice(1);
});

const statusClass = computed(() => {
    if (statusValue.value === 'valid') {
        return 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    }
    if (statusValue.value === 'expired' || statusValue.value === 'invalid') {
        return 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    if (statusValue.value === 'unreachable') {
        return 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    }

    return 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200';
});

const statusMessage = computed(() => String(props.sslStatus?.message || '-'));
const daysRemaining = computed(() => {
    const value = Number(props.sslStatus?.days_remaining);
    return Number.isFinite(value) ? value : null;
});

const syncVhost = async () => {
    if (syncLoading.value) {
        return;
    }

    syncMessage.value = '';
    syncLoading.value = true;

    try {
        const response = await fetch(panelRoute('websites.vhost.sync', { id: props.website.id }), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            body: JSON.stringify({}),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw data;
        }

        syncMessageType.value = String(data.type || 'success');
        syncMessage.value = String(data.message || 'Live vhost synced successfully.');
    } catch (error) {
        syncMessageType.value = 'error';
        syncMessage.value = String(error?.message || error?.errors?.vhost_sync || 'Live vhost sync failed.');
    } finally {
        syncLoading.value = false;
    }
};
</script>

<template>
    <Head title="SSL Manager" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">SSL Manager</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        SSL issue, check, and certificate status for {{ website.domain || '-' }}.
                    </p>
                </div>

            </div>
        </template>


            <div class="flex justify-end gap-2 mb-6">
                <Link :href="panelRoute('websites.manage', { id: website.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                   <i class="bi bi-arrow-left mr-2"></i> Back to Manage
                </Link>
                <Link :href="panelRoute('websites.usage', { id: website.id })" class="rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20">
                   <i class="bi bi-graph-up mr-2"></i>  Usage Details
                </Link>
            </div>
        <div class="space-y-6">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>
            <div v-if="syncMessage" :class="syncMessageType === 'success'
                ? 'rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700'
                : 'rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700'">
                {{ syncMessage }}
            </div>
            <div
                v-if="autoRenewNotice"
                class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700"
            >
                {{ autoRenewNotice }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="statusClass">{{ statusLabel }}</span>
                    <span class="rounded-full border px-3 py-1 text-xs font-medium" :class="website.enable_ssl ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300' : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300'">
                        Request SSL: {{ website.enable_ssl ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>

                <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ statusMessage }}</p>
                <p class="mt-1 text-xs text-slate-500">Checked at: {{ formatDate(sslStatus.checked_at) }}</p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Domain</p>
                        <p class="mt-1 text-sm font-semibold break-all">{{ sslStatus.domain || website.domain || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Subject CN</p>
                        <p class="mt-1 text-sm font-semibold break-all">{{ sslStatus.subject_cn || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Issuer CN</p>
                        <p class="mt-1 text-sm font-semibold break-all">{{ sslStatus.issuer_cn || '-' }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Days Remaining</p>
                        <p class="mt-1 text-sm font-semibold">{{ daysRemaining === null ? '-' : daysRemaining }}</p>
                    </div>
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Valid From</p>
                        <p class="mt-1 text-sm font-semibold">{{ formatDate(sslStatus.valid_from) }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Valid Until</p>
                        <p class="mt-1 text-sm font-semibold">{{ formatDate(sslStatus.valid_to) }}</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Link :href="panelRoute('websites.ssl', { id: website.id })" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                        Check SSL Now
                    </Link>
                    <Link
                        :href="panelRoute('websites.ssl.issue', { id: website.id })"
                        method="post"
                        as="button"
                        class="rounded-md border border-emerald-300 px-3 py-2 text-sm text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/20"
                    >
                        Issue / Renew SSL
                    </Link>
                    <button
                        type="button"
                        :disabled="syncLoading"
                        class="rounded-md border border-violet-300 px-3 py-2 text-sm text-violet-700 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-violet-700 dark:text-violet-300 dark:hover:bg-violet-900/20"
                        @click="syncVhost"
                    >
                        Sync VHost
                    </button>
                    <Link :href="panelRoute('websites.web-server', { id: website.id })" class="rounded-md border border-cyan-300 px-3 py-2 text-sm text-cyan-700 hover:bg-cyan-50 dark:border-cyan-700 dark:text-cyan-300 dark:hover:bg-cyan-900/20">
                        Apache + Nginx Service
                    </Link>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

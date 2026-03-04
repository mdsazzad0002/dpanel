<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';

const page = usePage();
const activeSubscription = computed(() => page.props.auth?.active_subscription ?? null);
const subscriptionQuotas = computed(() => page.props.auth?.subscription_quotas ?? {});

const showQuota = (resource) => {
    const quota = subscriptionQuotas.value?.[resource];

    if (!quota) return 'Not configured';
    if (quota.limit === null) return `${quota.used} used / Unlimited`;

    return `${quota.used} used / ${quota.limit} total`;
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Server Dashboard</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Basic structure for hosting and server management.</p>
            </div>
        </template>

        <div class="space-y-6">
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Subscription Package</h2>
                <div v-if="activeSubscription" class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Package</p>
                        <p class="text-sm font-medium">{{ activeSubscription.package?.name || activeSubscription.plan_name }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Mail Accounts</p>
                        <p class="text-sm font-medium">{{ showQuota('mail_accounts') }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Disk Space</p>
                        <p class="text-sm font-medium">{{ showQuota('disk_space_mb') }} MB</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Databases / Files</p>
                        <p class="text-sm font-medium">{{ showQuota('databases') }}</p>
                        <p class="text-sm font-medium">{{ showQuota('files') }}</p>
                    </div>
                </div>
                <p v-else class="mt-3 text-sm text-amber-600">No active subscription package found.</p>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">CPU Load</p>
                    <p class="mt-2 text-2xl font-semibold">34%</p>
                    <p class="mt-1 text-xs text-emerald-600">Normal</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Memory</p>
                    <p class="mt-2 text-2xl font-semibold">5.8 GB</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">of 16 GB</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Websites</p>
                    <p class="mt-2 text-2xl font-semibold">12</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">2 pending SSL renewal</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Mail Queue</p>
                    <p class="mt-2 text-2xl font-semibold">8</p>
                    <p class="mt-1 text-xs text-amber-600">Needs review</p>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-5 xl:col-span-2 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Services</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">Apache</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Running - Port 80/443</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">Mail Server</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Postfix + Dovecot</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">MySQL/MariaDB</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Healthy - 31 active connections</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <p class="text-sm font-medium">Firewall</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Active - UFW profile loaded</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Quick Actions</h2>
                    <div class="mt-4 space-y-2">
                        <button class="w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                            Create Website
                        </button>
                        <button class="w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                            Add Mailbox
                        </button>
                        <button class="w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                            Open Terminal
                        </button>
                        <button class="w-full rounded-lg border border-slate-300 px-3 py-2 text-left text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                            Generate Backup
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const deleteForm = useForm({});

defineProps({
    mailboxes: {
        type: Array,
        default: () => [],
    },
    setupCheck: {
        type: Object,
        default: () => ({
            services: {},
            messages: [],
            webmail_url: '',
            webmail_login_url: '',
            autologin_ready: false,
            webmail_reachable: null,
            storage_backend_ready: false,
        }),
    },
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const deleteMailbox = (id) => {
    if (!confirm('Delete this mailbox?')) return;
    deleteForm.delete(route('emails.destroy', id));
};

const serviceBadgeClass = (status) => {
    const value = String(status || '').toLowerCase();
    if (value === 'running') return 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    if (value === 'down') return 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300';
    return 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
};
</script>

<template>
    <Head title="List Emails" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">List Emails</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">View and manage mailbox accounts.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>

            <div class="flex justify-end">
                <Link :href="route('emails.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create Email
                </Link>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Mail Setup Check</h2>
                        <p class="mt-1 break-all text-sm text-slate-600 dark:text-slate-300">{{ setupCheck.webmail_url || '-' }}</p>
                        <p class="mt-1 break-all text-xs text-slate-500 dark:text-slate-400">Login endpoint: {{ setupCheck.webmail_login_url || '-' }}</p>
                    </div>
                    <span
                        class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="setupCheck.autologin_ready
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                            : 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300'"
                    >
                        {{ setupCheck.autologin_ready ? 'Auto Login Ready' : 'Auto Login Not Ready' }}
                    </span>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full border px-2 py-1" :class="serviceBadgeClass(setupCheck.services?.postfix)">Postfix: {{ setupCheck.services?.postfix || 'unknown' }}</span>
                    <span class="rounded-full border px-2 py-1" :class="serviceBadgeClass(setupCheck.services?.dovecot)">Dovecot: {{ setupCheck.services?.dovecot || 'unknown' }}</span>
                    <span
                        class="rounded-full border px-2 py-1"
                        :class="setupCheck.storage_backend_ready
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                            : 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300'"
                    >
                        Storage Sync: {{ setupCheck.storage_backend_ready ? 'ready' : 'not ready' }}
                    </span>
                    <span
                        class="rounded-full border px-2 py-1"
                        :class="setupCheck.webmail_reachable === true
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                            : setupCheck.webmail_reachable === false
                                ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300'
                                : 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'"
                    >
                        Webmail: {{ setupCheck.webmail_reachable === true ? 'reachable' : setupCheck.webmail_reachable === false ? 'unreachable' : 'unknown' }}
                    </span>
                </div>

                <ul v-if="Array.isArray(setupCheck.messages) && setupCheck.messages.length > 0" class="mt-3 space-y-1 text-xs text-red-600 dark:text-red-300">
                    <li v-for="(message, index) in setupCheck.messages" :key="`setup-message-${index}`">- {{ message }}</li>
                </ul>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Attached Website</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Quota</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in mailboxes" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ item.domain || '-' }}</td>
                            <td class="px-4 py-3 font-medium">
                                <p>{{ item.email }}</p>
                                <p
                                    class="mt-1 text-xs"
                                    :class="item.autologin_ready ? 'text-emerald-600 dark:text-emerald-300' : 'text-red-600 dark:text-red-300'"
                                >
                                    {{ item.autologin_message || '-' }}
                                </p>
                            </td>
                            <td class="px-4 py-3">{{ item.quota_mb }} MB</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs text-emerald-700">
                                    {{ item.status || 'active' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ formatDate(item.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a
                                        v-if="item.autologin_ready"
                                        :href="route('emails.login', item.id)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20"
                                    >
                                        Roundcube Login
                                    </a>
                                    <span
                                        v-else
                                        class="cursor-not-allowed rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-400 dark:border-slate-700 dark:text-slate-500"
                                        :title="item.autologin_message || 'Auto login check failed.'"
                                    >
                                        Login Blocked
                                    </span>
                                    <Link :href="route('emails.edit', item.id)" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                                        Edit
                                    </Link>
                                    <button
                                        :disabled="deleteForm.processing"
                                        class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:text-red-400"
                                        @click="deleteMailbox(item.id)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="mailboxes.length === 0">
                            <td colspan="6" class="px-4 py-6 text-center text-slate-500">No mailbox found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

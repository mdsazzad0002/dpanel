<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const deleteForm = useForm({});
const panelToken = page.props.panel?.token;

const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    mailboxes: {
        type: Array,
        default: () => [],
    },
});

const selectedMailboxId = ref(props.mailboxes[0]?.id || '');
const selectedMailbox = computed(() => props.mailboxes.find((mailbox) => String(mailbox.id) === String(selectedMailboxId.value)) || props.mailboxes[0] || null);
const guideEmail = computed(() => selectedMailbox.value?.email || 'you@example.com');
const guideHost = computed(() => selectedMailbox.value?.domain ? `mail.${selectedMailbox.value.domain}` : 'mail.example.com');
const copiedValue = ref('');
const copyValue = async (value) => {
    await navigator.clipboard?.writeText(String(value));
    copiedValue.value = String(value);
    window.setTimeout(() => { if (copiedValue.value === String(value)) copiedValue.value = ''; }, 1500);
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

const deleteMailbox = (id) => {
    if (!confirm('Delete this mailbox?')) return;
    deleteForm.delete(panelRoute('emails.destroy', { id }));
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

            <div class="flex justify-end gap-2">
                <Link :href="panelRoute('emails.guide')" class="rounded-md border border-blue-300 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20">
                    <i class="bi bi-book mr-1"></i> Mail DNS Guide
                </Link>
                <Link :href="panelRoute('emails.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">
                    Create Email
                </Link>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">DNS Management</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                            Mail DNS and DKIM helpers now live in the DNS management area.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="panelRoute('dns.zones')" class="rounded-md border border-blue-300 px-3 py-2 text-sm text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20">
                            DNS Zones
                        </Link>
                        <Link :href="panelRoute('dns.zones')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                            DNS Zones
                        </Link>
                    </div>
                </div>
            </div>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-semibold text-slate-900 dark:text-white">Email client connection guide</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Use these settings in Outlook, Thunderbird, Apple Mail, Gmail, or a mobile mail app.</p>
                    </div>
                    <label v-if="mailboxes.length" class="text-sm text-slate-600 dark:text-slate-300">
                        Mailbox
                        <select v-model="selectedMailboxId" class="ml-2 rounded-md border-slate-300 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950">
                            <option v-for="mailbox in mailboxes" :key="mailbox.id" :value="mailbox.id">{{ mailbox.email }}</option>
                        </select>
                    </label>
                </div>

                <div class="grid gap-4 p-4 lg:grid-cols-2">
                    <article class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                        <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-lg bg-blue-600 text-white"><i class="bi bi-inbox"></i></span><div><h3 class="font-semibold text-blue-950 dark:text-blue-100">Incoming mail — IMAP</h3><p class="text-xs text-blue-700 dark:text-blue-300">Keeps email synchronized across devices</p></div></div>
                        <dl class="mt-4 divide-y divide-blue-200 text-sm dark:divide-blue-900">
                            <div v-for="row in [{ label: 'Server', value: guideHost }, { label: 'Port', value: '993' }, { label: 'Encryption', value: 'SSL/TLS' }, { label: 'Authentication', value: 'Normal password' }, { label: 'Username', value: guideEmail }]" :key="row.label" class="flex items-center justify-between gap-4 py-2.5">
                                <dt class="text-blue-700 dark:text-blue-300">{{ row.label }}</dt>
                                <dd class="flex min-w-0 items-center gap-2 font-mono font-medium text-blue-950 dark:text-blue-100"><span class="truncate">{{ row.value }}</span><button type="button" class="text-blue-600 hover:text-blue-800" :title="`Copy ${row.label}`" @click="copyValue(row.value)"><i :class="copiedValue === String(row.value) ? 'bi bi-check2' : 'bi bi-copy'"></i></button></dd>
                            </div>
                        </dl>
                    </article>

                    <article class="rounded-xl border border-violet-200 bg-violet-50/60 p-4 dark:border-violet-900 dark:bg-violet-950/30">
                        <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-lg bg-violet-600 text-white"><i class="bi bi-send"></i></span><div><h3 class="font-semibold text-violet-950 dark:text-violet-100">Outgoing mail — SMTP</h3><p class="text-xs text-violet-700 dark:text-violet-300">Authentication is required for sending</p></div></div>
                        <dl class="mt-4 divide-y divide-violet-200 text-sm dark:divide-violet-900">
                            <div v-for="row in [{ label: 'Server', value: guideHost }, { label: 'Port', value: '465' }, { label: 'Encryption', value: 'SSL/TLS' }, { label: 'Alternative', value: '587 with STARTTLS' }, { label: 'Username', value: guideEmail }]" :key="row.label" class="flex items-center justify-between gap-4 py-2.5">
                                <dt class="text-violet-700 dark:text-violet-300">{{ row.label }}</dt>
                                <dd class="flex min-w-0 items-center gap-2 font-mono font-medium text-violet-950 dark:text-violet-100"><span class="truncate">{{ row.value }}</span><button type="button" class="text-violet-600 hover:text-violet-800" :title="`Copy ${row.label}`" @click="copyValue(row.value)"><i :class="copiedValue === String(row.value) ? 'bi bi-check2' : 'bi bi-copy'"></i></button></dd>
                            </div>
                        </dl>
                    </article>
                </div>

                <div class="border-t border-slate-200 bg-slate-50 p-4 text-sm dark:border-slate-800 dark:bg-slate-950/50">
                    <h3 class="font-semibold">Setup steps</h3>
                    <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-slate-600 dark:text-slate-300">
                        <li>Choose <strong>Add account</strong> and then <strong>IMAP</strong> or <strong>Manual setup</strong> in your mail app.</li>
                        <li>Enter the complete email address as the username for both incoming and outgoing servers.</li>
                        <li>Use the mailbox password created in dPanel. Enable SMTP authentication with the same username and password.</li>
                        <li>Accept only a valid TLS certificate matching <span class="font-mono">{{ guideHost }}</span>; do not bypass certificate warnings.</li>
                    </ol>
                </div>
            </section>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Attached Website</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Plan</th>
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
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="item.plan" class="rounded-full bg-blue-100 px-2 py-1 text-xs text-blue-700">
                                    {{ item.plan.name }}
                                </span>
                                <span v-else class="text-xs text-slate-400">No plan</span>
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
                                    <Link
                                        v-if="item.autologin_ready"
                                        :href="panelRoute('mailbox.open', { id: item.id })"
                                        class="rounded-md border border-blue-300 px-2 py-1 text-xs text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20"
                                    >
                                        Open Mailbox
                                    </Link>
                                    <span
                                        v-else
                                        class="cursor-not-allowed rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-400 dark:border-slate-700 dark:text-slate-500"
                                        :title="item.autologin_message || 'Auto login check failed.'"
                                    >
                                        Login Blocked
                                    </span>
                                    <Link :href="panelRoute('emails.edit', { id: item.id })" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
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
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">No mailbox found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

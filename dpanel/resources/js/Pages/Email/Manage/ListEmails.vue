<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';

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

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString();
};

// Status changes in place, so turning a mailbox on/off does not reload the list.
const rows = ref(props.mailboxes.map((row) => ({ ...row })));
watch(() => props.mailboxes, (value) => { rows.value = value.map((row) => ({ ...row })); });
const togglingId = ref('');
const result = ref(null);

const isOn = (item) => (item.status || 'active') === 'active';

const toggleMailbox = async (item) => {
    const turningOn = !isOn(item);
    if (!turningOn && !confirm(`Turn off ${item.email}? Mail for it will be refused and it cannot log in until it is turned on again.`)) return;
    togglingId.value = item.id;
    try {
        const { data } = await window.axios.post(panelRoute(turningOn ? 'emails.enable' : 'emails.disable', { id: item.id }), {}, { headers: { Accept: 'application/json' } });
        Object.assign(item, data?.mailbox ?? {});
        // Turning on always shows the checks; turning off needs no modal.
        if (turningOn) {
            result.value = { ok: Boolean(data?.enabled), title: data?.title ?? '', message: data?.message ?? '', checks: data?.checks ?? [] };
        }
    } catch (error) {
        result.value = { ok: false, title: 'Could not change the mailbox', message: error?.response?.data?.message || error?.message || 'Request failed.', checks: [] };
    } finally {
        togglingId.value = '';
    }
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
                        <tr v-for="item in rows" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
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
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="isOn(item)"
                                        :disabled="togglingId === item.id"
                                        :title="isOn(item) ? 'Turn off' : 'Turn on (runs all checks first)'"
                                        class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition disabled:opacity-60"
                                        :class="isOn(item) ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"
                                        @click="toggleMailbox(item)"
                                    >
                                        <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition" :class="isOn(item) ? 'translate-x-4' : 'translate-x-0.5'"></span>
                                    </button>
                                    <span class="text-xs" :class="isOn(item) ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500'">
                                        {{ togglingId === item.id ? (isOn(item) ? 'Turning off…' : 'Checking…') : (isOn(item) ? 'On' : 'Off') }}
                                    </span>
                                </div>
                                <p v-if="item.health_error" class="mt-1 max-w-xs break-words text-xs" :class="isOn(item) ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'">
                                    {{ item.health_error }}
                                </p>
                            </td>
                            <td class="px-4 py-3">{{ formatDate(item.created_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <Link :href="panelRoute('emails.connect-device', { id: item.id })" class="rounded-md border border-violet-300 px-2 py-1 text-xs text-violet-700 hover:bg-violet-50 dark:border-violet-700 dark:text-violet-300 dark:hover:bg-violet-900/20">
                                        Connect Device
                                    </Link>
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
        <Modal :show="result !== null" max-width="lg" @close="result = null">
            <div v-if="result" class="p-6">
                <div class="flex items-start gap-3">
                    <i class="bi text-2xl" :class="result.ok ? 'bi-check-circle-fill text-emerald-600' : 'bi-x-octagon-fill text-red-600'"></i>
                    <div class="min-w-0">
                        <h2 class="break-words text-lg font-semibold text-slate-900 dark:text-slate-100">{{ result.title }}</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ result.message }}</p>
                    </div>
                </div>
                <ul v-if="result.checks.length" class="mt-4 space-y-2">
                    <li v-for="check in result.checks" :key="check.name" class="rounded-lg p-3 text-sm" :class="check.ok ? 'bg-emerald-50 dark:bg-emerald-950/40' : 'bg-red-50 dark:bg-red-950/40'">
                        <p class="font-medium text-slate-900 dark:text-slate-100">
                            <i class="bi mr-1" :class="check.ok ? 'bi-check-circle text-emerald-600' : 'bi-x-circle text-red-600'"></i>{{ check.name }}
                        </p>
                        <p class="mt-1 break-words text-xs text-slate-600 dark:text-slate-300">{{ check.message }}</p>
                    </li>
                </ul>
                <div class="mt-6 flex justify-end">
                    <button type="button" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white" @click="result = null">Close</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

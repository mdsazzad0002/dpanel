<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    zones: { type: Array, default: () => [] },
    websiteDomains: { type: Array, default: () => [] },
    cloudflareGuide: {
        type: Object,
        default: () => ({
            notes: [],
            records: [],
            mail_domains: [],
        }),
    },
    mailGuide: {
        type: Object,
        default: () => ({
            domains: [],
            notes: [],
            records: [],
        }),
    },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);
const editingId = ref(null);
const deleteForm = useForm({});
const syncAllForm = useForm({ domain: '' });
const syncZoneForm = useForm({ domain: '' });

const form = useForm({
    domain: '',
    type: 'master',
    email: '',
    refresh: 3600,
    retry: 600,
    expire: 1209600,
    minimum_ttl: 3600,
    status: 'active',
});

const submit = () => {
    if (editingId.value) {
        form.patch(panelRoute('dns.zones.update', { id: editingId.value }), { onSuccess: resetForm });
        return;
    }
    form.post(panelRoute('dns.zones.store'), { onSuccess: resetForm });
};

const editItem = (item) => {
    editingId.value = item.id;
    form.domain = item.domain ?? '';
    form.type = item.type ?? 'master';
    form.email = item.email ?? '';
    form.refresh = Number(item.refresh ?? 3600);
    form.retry = Number(item.retry ?? 600);
    form.expire = Number(item.expire ?? 1209600);
    form.minimum_ttl = Number(item.minimum_ttl ?? 3600);
    form.status = item.status ?? 'active';
};

const resetForm = () => {
    editingId.value = null;
    form.reset();
    form.type = 'master';
    form.refresh = 3600;
    form.retry = 600;
    form.expire = 1209600;
    form.minimum_ttl = 3600;
    form.status = 'active';
};

const deleteItem = (id) => {
    if (!confirm('Delete this zone? Related records will be removed.')) return;
    deleteForm.delete(panelRoute('dns.zones.destroy', { id }));
};

const syncAll = () => {
    syncAllForm.domain = '';
    syncAllForm.post(panelRoute('dns.cloudflare.sync'));
};

const syncZone = (domain) => {
    syncZoneForm.domain = domain;
    syncZoneForm.post(panelRoute('dns.cloudflare.sync'));
};
</script>

<template>
    <Head title="DNS Zones" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">DNS Zones</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">PowerDNS authoritative zones (stored in domains and SOA records).</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ page.props.flash.error }}
            </div>
            <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                Type mapping: <strong>master</strong> -> PowerDNS NATIVE, <strong>slave</strong> -> PowerDNS SLAVE.
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Cloudflare Mail Setup</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                            Mail domains: {{ Array.isArray(cloudflareGuide.mail_domains) && cloudflareGuide.mail_domains.length > 0 ? cloudflareGuide.mail_domains.join(', ') : 'none detected' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span
                            class="rounded-full border px-2 py-1"
                            :class="cloudflareGuide.api_token_ready
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                                : 'border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300'"
                        >
                            API token {{ cloudflareGuide.api_token_ready ? 'ready' : 'missing' }}
                        </span>
                        <span
                            class="rounded-full border px-2 py-1"
                            :class="cloudflareGuide.zone_map_ready
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                                : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300'"
                        >
                            Zone map {{ cloudflareGuide.zone_map_ready ? 'ready' : 'optional' }}
                        </span>
                        <span
                            class="rounded-full border px-2 py-1"
                            :class="cloudflareGuide.sync_proxied
                                ? 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300'
                                : 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'"
                        >
                            Sync proxied {{ cloudflareGuide.sync_proxied ? 'on' : 'off' }}
                        </span>
                    </div>
                </div>

                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-800 dark:bg-slate-950">
                        <p class="font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Required Records</p>
                        <table class="mt-2 min-w-full text-left">
                            <thead>
                                <tr class="text-slate-500 dark:text-slate-400">
                                    <th class="py-1 pr-2">Type</th>
                                    <th class="py-1 pr-2">Name</th>
                                    <th class="py-1 pr-2">Content</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(record, index) in cloudflareGuide.records" :key="`cloudflare-record-${index}`" class="border-t border-slate-200 dark:border-slate-800">
                                    <td class="py-2 pr-2 font-medium">{{ record.type }}</td>
                                    <td class="py-2 pr-2 break-all">{{ record.name }}</td>
                                    <td class="py-2 pr-2 break-all">{{ record.content }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-800 dark:bg-slate-950">
                        <p class="font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Notes</p>
                        <ul class="mt-2 space-y-1 text-slate-600 dark:text-slate-300">
                            <li v-for="(note, index) in cloudflareGuide.notes" :key="`cloudflare-note-${index}`">- {{ note }}</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Mail DNS & DKIM</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                            Primary domain: {{ mailGuide.primary_domain || '-' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Selector: {{ mailGuide.selector || 'default' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Mail host: {{ mailGuide.mail_host || '-' }}
                        </p>
                    </div>
                    <span
                        class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="mailGuide.dkim_public_key_ready
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                            : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300'"
                    >
                        {{ mailGuide.dkim_public_key_ready ? 'DKIM Key Ready' : 'DKIM Key Missing' }}
                    </span>
                </div>

                <div v-if="Array.isArray(mailGuide.domains) && mailGuide.domains.length > 0" class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    Mail domains: {{ mailGuide.domains.join(', ') }}
                </div>

                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-800 dark:bg-slate-950">
                        <p class="font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">DKIM TXT</p>
                        <p class="mt-1 break-all text-slate-700 dark:text-slate-200">{{ mailGuide.dkim_record_name || '-' }}</p>
                        <p class="mt-2 break-all rounded-md bg-white p-2 text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                            {{ mailGuide.dkim_record_value || '-' }}
                        </p>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-800 dark:bg-slate-950">
                        <p class="font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Mail Notes</p>
                        <ul class="mt-2 space-y-1 text-slate-600 dark:text-slate-300">
                            <li v-for="(note, index) in mailGuide.notes" :key="`mail-guide-note-${index}`">- {{ note }}</li>
                        </ul>
                    </div>
                </div>

                <div class="mt-3 overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-800">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">Type</th>
                                <th class="px-3 py-2">Name</th>
                                <th class="px-3 py-2">Content</th>
                                <th class="px-3 py-2">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(record, index) in mailGuide.records" :key="`mail-guide-record-${index}`" class="border-t border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-2 font-medium">{{ record.type }}</td>
                                <td class="px-3 py-2 break-all">{{ record.name }}</td>
                                <td class="px-3 py-2 break-all">{{ record.content }}</td>
                                <td class="px-3 py-2 text-slate-500 dark:text-slate-400">{{ record.note }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="flex items-center justify-end">
                <button type="button" :disabled="syncAllForm.processing" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60" @click="syncAll">
                    Sync All To Cloudflare
                </button>
            </div>

            <form class="grid gap-4 rounded-xl border border-slate-200 bg-white p-6 md:grid-cols-3 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm">Domain</label>
                    <select v-model="form.domain" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Select domain</option>
                        <option v-for="domain in websiteDomains" :key="domain" :value="domain">{{ domain }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">If domain is not listed, type it manually below.</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Domain (Manual Input)</label>
                    <input v-model="form.domain" type="text" placeholder="example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Type</label>
                    <select v-model="form.type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="master">master</option>
                        <option value="slave">slave</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">SOA Email</label>
                    <input v-model="form.email" type="email" placeholder="hostmaster@example.com" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Refresh</label>
                    <input v-model.number="form.refresh" type="number" min="300" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Retry</label>
                    <input v-model.number="form.retry" type="number" min="60" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Expire</label>
                    <input v-model.number="form.expire" type="number" min="3600" max="2592000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Minimum TTL</label>
                    <input v-model.number="form.minimum_ttl" type="number" min="60" max="86400" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Status</label>
                    <select v-model="form.status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="active">active</option>
                        <option value="disabled">disabled</option>
                    </select>
                </div>
                <div class="md:col-span-3 flex items-center gap-2">
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {{ editingId ? 'Update Zone' : 'Create Zone' }}
                    </button>
                    <button v-if="editingId" type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="resetForm">
                        Cancel
                    </button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3">Domain</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Refresh</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in zones" :key="item.id" class="border-t border-slate-200 dark:border-slate-800">
                            <td class="px-4 py-3">{{ item.domain }}</td>
                            <td class="px-4 py-3">{{ item.type }}</td>
                            <td class="px-4 py-3">{{ item.email }}</td>
                            <td class="px-4 py-3">{{ item.refresh }}</td>
                            <td class="px-4 py-3">{{ item.status }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="editItem(item)">Edit</button>
                                    <button type="button" :disabled="syncZoneForm.processing" class="rounded-md border border-indigo-300 px-2 py-1 text-xs text-indigo-700 hover:bg-indigo-50 disabled:opacity-60 dark:border-indigo-700 dark:text-indigo-300" @click="syncZone(item.domain)">Cloudflare Sync</button>
                                    <button type="button" class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400" @click="deleteItem(item.id)">Delete</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="zones.length === 0">
                            <td colspan="10" class="px-4 py-6 text-center text-slate-500">No DNS zones found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

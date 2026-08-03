<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    records: { type: Array, default: () => [] },
    zoneDomains: { type: Array, default: () => [] },
    selectedDomain: { type: String, default: '' },
    tokenConfigured: { type: Boolean, default: false },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params));
const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'];
const jsonInput = ref('');
const jsonError = ref('');
let nextId = 1;

const makeRecord = (record = {}) => ({
    _key: nextId++,
    selected: record.selected ?? true,
    zone_domain: record.zone_domain || props.selectedDomain || props.zoneDomains[0] || '',
    type: String(record.type || 'A').toUpperCase(),
    name: record.name || '',
    content: typeof record.content === 'string' ? record.content : '',
    ttl: Number(record.ttl || 3600),
    priority: record.priority ?? null,
    proxied: Boolean(record.proxied),
});

const rows = ref(props.records.map(makeRecord));
const form = useForm({ records: [] });
const selectedCount = computed(() => rows.value.filter((record) => record.selected).length);

const addRecord = () => rows.value.unshift(makeRecord());
const removeRecord = (key) => { rows.value = rows.value.filter((record) => record._key !== key); };
const setAll = (selected) => rows.value.forEach((record) => { record.selected = selected; });

const inferZone = (name, fallback = '') => {
    const normalized = String(name || '').toLowerCase().replace(/\.$/, '');
    return [...props.zoneDomains]
        .sort((a, b) => b.length - a.length)
        .find((zone) => normalized === zone || normalized.endsWith(`.${zone}`)) || fallback || props.selectedDomain || '';
};

const importJson = () => {
    jsonError.value = '';
    try {
        const decoded = JSON.parse(jsonInput.value);
        const source = Array.isArray(decoded) ? decoded : (Array.isArray(decoded.result) ? decoded.result : (Array.isArray(decoded.records) ? decoded.records : null));
        if (!source) throw new Error('Use a JSON array, Cloudflare API response, or an object with a records array.');

        const imported = source.map((record) => {
            const type = String(record.type || 'A').toUpperCase();
            const content = type === 'SRV' && record.data
                ? JSON.stringify(record.data)
                : String(record.content ?? '');
            return makeRecord({
                zone_domain: inferZone(record.name, record.zone_domain),
                type,
                name: String(record.name || '').replace(/\.$/, ''),
                content,
                ttl: Number(record.ttl || 3600),
                priority: record.priority ?? null,
                proxied: Boolean(record.proxied),
            });
        }).filter((record) => record.zone_domain && record.name && recordTypes.includes(record.type));

        rows.value.push(...imported);
        jsonInput.value = '';
        if (!imported.length) jsonError.value = 'No supported records matched a local zone.';
    } catch (error) {
        jsonError.value = error instanceof Error ? error.message : 'Invalid JSON.';
    }
};

const importJsonFile = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    jsonInput.value = await file.text();
    importJson();
    event.target.value = '';
};

const submit = () => {
    form.records = rows.value.filter((record) => record.selected).map(({ _key, selected, ...record }) => ({
        ...record,
        ttl: Number(record.ttl),
        priority: record.priority === '' || record.priority === null ? null : Number(record.priority),
    }));
    form.post(panelRoute('dns.cloudflare.sync'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Review Cloudflare Sync" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Review Cloudflare Sync</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Choose, edit, add, or remove draft records. Cloudflare changes only after final submit.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>
            <div v-if="!tokenConfigured" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Cloudflare API token is not configured. You can prepare the draft, but final submit needs CLOUDFLARE_API_TOKEN.</div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Cloudflare JSON import</h2>
                        <p class="mt-1 text-sm text-slate-500">Accepts a records array, an object with <code>records</code>, or a Cloudflare API response with <code>result</code>. Imported records are added to the draft.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <label class="cursor-pointer rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                            Choose JSON file
                            <input type="file" accept="application/json,.json" class="hidden" @change="importJsonFile" />
                        </label>
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="importJson">Import pasted JSON</button>
                    </div>
                </div>
                <textarea v-model="jsonInput" rows="6" class="mt-4 w-full rounded-md border border-slate-300 font-mono text-xs dark:border-slate-700 dark:bg-slate-950" placeholder='[{"type":"A","name":"example.com","content":"192.0.2.10","ttl":3600,"proxied":false}]'></textarea>
                <p v-if="jsonError" class="mt-2 text-sm text-red-600">{{ jsonError }}</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-800">
                    <div>
                        <h2 class="font-semibold">Draft records</h2>
                        <p class="text-sm text-slate-500">{{ selectedCount }} selected out of {{ rows.length }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="setAll(true)">Select all</button>
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="setAll(false)">Clear</button>
                        <button type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white hover:bg-indigo-700" @click="addRecord">+ Add record</button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-[1150px] w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800"><tr><th class="p-3">Use</th><th class="p-3">Zone</th><th class="p-3">Type</th><th class="p-3">Name</th><th class="p-3">Content / SRV data JSON</th><th class="p-3">TTL</th><th class="p-3">Priority</th><th class="p-3">Proxy</th><th class="p-3"></th></tr></thead>
                        <tbody>
                            <tr v-for="record in rows" :key="record._key" class="border-t border-slate-200 dark:border-slate-800" :class="{ 'opacity-50': !record.selected }">
                                <td class="p-3"><input v-model="record.selected" type="checkbox" class="rounded" /></td>
                                <td class="p-3"><select v-model="record.zone_domain" class="w-44 rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900"><option v-for="zone in zoneDomains" :key="zone" :value="zone">{{ zone }}</option></select></td>
                                <td class="p-3"><select v-model="record.type" class="rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900"><option v-for="type in recordTypes" :key="type" :value="type">{{ type }}</option></select></td>
                                <td class="p-3"><input v-model="record.name" class="w-52 rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" /></td>
                                <td class="p-3"><input v-model="record.content" class="w-72 rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" /></td>
                                <td class="p-3"><input v-model.number="record.ttl" type="number" min="1" max="86400" class="w-24 rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" /></td>
                                <td class="p-3"><input v-model.number="record.priority" type="number" min="0" max="65535" :disabled="record.type !== 'MX'" class="w-24 rounded-md border-slate-300 text-sm disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900" /></td>
                                <td class="p-3"><input v-model="record.proxied" type="checkbox" :disabled="!['A', 'AAAA', 'CNAME'].includes(record.type)" class="rounded disabled:opacity-40" /></td>
                                <td class="p-3"><button type="button" class="text-red-600 hover:underline" @click="removeRecord(record._key)">Remove</button></td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="9" class="p-8 text-center text-slate-500">No draft records. Add one or import Cloudflare JSON.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="form.hasErrors" class="border-t border-red-200 bg-red-50 px-5 py-3 text-sm text-red-700">Please correct the invalid record fields and submit again.</div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-5 dark:border-slate-800">
                    <Link :href="panelRoute('dns.zones')" class="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">Back to DNS zones</Link>
                    <button type="button" :disabled="form.processing || selectedCount === 0 || !tokenConfigured" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50" @click="submit">{{ form.processing ? 'Syncing…' : `Final submit (${selectedCount})` }}</button>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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

const stripBindComment = (line) => {
    let quoted = false;
    let escaped = false;
    for (let index = 0; index < line.length; index += 1) {
        const char = line[index];
        if (escaped) { escaped = false; continue; }
        if (char === '\\') { escaped = true; continue; }
        if (char === '"') { quoted = !quoted; continue; }
        if (char === ';' && !quoted) return { value: line.slice(0, index), comment: line.slice(index + 1) };
    }
    return { value: line, comment: '' };
};

const tokenizeBind = (value) => value.match(/"(?:\\.|[^"\\])*"|\S+/g) || [];
const decodeBindText = (token) => token.startsWith('"')
    ? token.slice(1, -1).replace(/\\(["\\])/g, '$1')
    : token.replace(/\.$/, '');

const importBindZone = (input) => {
    const imported = [];
    let origin = props.selectedDomain || props.zoneDomains[0] || '';

    input.split(/\r?\n/).forEach((rawLine) => {
        const { value, comment } = stripBindComment(rawLine);
        const line = value.trim();
        if (!line || line.startsWith(';')) return;
        if (/^\$ORIGIN\s+/i.test(line)) {
            origin = line.split(/\s+/)[1]?.replace(/\.$/, '') || origin;
            return;
        }
        if (line.startsWith('$')) return;

        const tokens = tokenizeBind(line);
        const inIndex = tokens.findIndex((token) => token.toUpperCase() === 'IN');
        if (inIndex < 1 || inIndex + 2 >= tokens.length) return;

        const ownerToken = tokens[0];
        const ttlToken = tokens.slice(1, inIndex).find((token) => /^\d+$/.test(token));
        const type = String(tokens[inIndex + 1]).toUpperCase();
        if (!recordTypes.includes(type)) return;

        const zoneDomain = inferZone(ownerToken === '@' ? origin : ownerToken, origin);
        if (!zoneDomain) return;
        const name = ownerToken === '@'
            ? zoneDomain
            : (ownerToken.endsWith('.') ? ownerToken.slice(0, -1) : (ownerToken.includes('.') ? ownerToken : `${ownerToken}.${zoneDomain}`));
        const data = tokens.slice(inIndex + 2);
        let priority = null;
        let content = '';

        if (type === 'MX') {
            priority = Number(data.shift() || 0);
            content = decodeBindText(data[0] || '');
        } else if (type === 'SRV') {
            const [srvPriority, weight, port, target] = data;
            const relative = name === zoneDomain ? '' : name.slice(0, -(zoneDomain.length + 1));
            const labels = relative.split('.');
            content = JSON.stringify({
                service: labels[0] || '',
                proto: labels[1] || '',
                name: labels.slice(2).join('.') || zoneDomain,
                priority: Number(srvPriority || 0),
                weight: Number(weight || 0),
                port: Number(port || 0),
                target: decodeBindText(target || ''),
            });
        } else if (type === 'TXT') {
            content = data.map(decodeBindText).join('');
        } else {
            content = decodeBindText(data[0] || '');
        }

        imported.push(makeRecord({
            zone_domain: zoneDomain,
            type,
            name,
            content,
            ttl: Number(ttlToken || 3600),
            priority,
            proxied: /cf-proxied\s*:\s*true/i.test(comment),
        }));
    });

    return imported;
};

const importJson = () => {
    jsonError.value = '';
    try {
        if (!jsonInput.value.trim().startsWith('{') && !jsonInput.value.trim().startsWith('[')) {
            const imported = importBindZone(jsonInput.value);
            rows.value.push(...imported);
            jsonInput.value = '';
            if (!imported.length) jsonError.value = t('dns.cloudflare.noMatch');
            return;
        }
        const decoded = JSON.parse(jsonInput.value);
        const source = Array.isArray(decoded) ? decoded : (Array.isArray(decoded.result) ? decoded.result : (Array.isArray(decoded.records) ? decoded.records : null));
        if (!source) throw new Error(t('dns.cloudflare.badJson'));

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
        if (!imported.length) jsonError.value = t('dns.cloudflare.noMatch');
    } catch (error) {
        jsonError.value = error instanceof Error ? error.message : t('dns.cloudflare.invalid');
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
    form.post(panelRoute('dns.cloudflare.import'), { preserveScroll: true });
};
</script>

<template>
    <Head :title="t('dns.cloudflare.title')" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">{{ t('dns.cloudflare.title') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('dns.cloudflare.subtitle') }}</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">{{ t('dns.cloudflare.importTitle') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ t('dns.cloudflare.importHelp') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <label class="cursor-pointer rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                            {{ t('dns.cloudflare.chooseFile') }}
                            <input type="file" accept="text/plain,.txt,.zone" class="hidden" @change="importJsonFile" />
                        </label>
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="importJson">{{ t('dns.cloudflare.importPasted') }}</button>
                    </div>
                </div>
                <textarea v-model="jsonInput" rows="6" class="mt-4 w-full rounded-md border border-slate-300 font-mono text-xs dark:border-slate-700 dark:bg-slate-950" placeholder="example.com. 3600 IN A 192.0.2.10 ; cf_tags=cf-proxied:false"></textarea>
                <p v-if="jsonError" class="mt-2 text-sm text-red-600">{{ jsonError }}</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-800">
                    <div>
                        <h2 class="font-semibold">{{ t('dns.cloudflare.draft') }}</h2>
                        <p class="text-sm text-slate-500">{{ t('dns.cloudflare.selected', { count: selectedCount, total: rows.length }) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="setAll(true)">{{ t('dns.cloudflare.selectAll') }}</button>
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="setAll(false)">{{ t('dns.cloudflare.clear') }}</button>
                        <button type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white hover:bg-indigo-700" @click="addRecord">+ {{ t('dns.cloudflare.add') }}</button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-[1150px] w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800"><tr><th class="p-3">{{ t('dns.cloudflare.use') }}</th><th class="p-3">{{ t('dns.cloudflare.zone') }}</th><th class="p-3">{{ t('dns.cloudflare.type') }}</th><th class="p-3">{{ t('dns.cloudflare.name') }}</th><th class="p-3">{{ t('dns.cloudflare.content') }}</th><th class="p-3">TTL</th><th class="p-3">{{ t('dns.cloudflare.priority') }}</th><th class="p-3">{{ t('dns.cloudflare.proxy') }}</th><th class="p-3"></th></tr></thead>
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
                                <td class="p-3"><button type="button" class="text-red-600 hover:underline" @click="removeRecord(record._key)">{{ t('dns.cloudflare.remove') }}</button></td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="9" class="p-8 text-center text-slate-500">{{ t('dns.cloudflare.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="form.hasErrors" class="border-t border-red-200 bg-red-50 px-5 py-3 text-sm text-red-700">{{ t('dns.cloudflare.fixErrors') }}</div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-5 dark:border-slate-800">
                    <Link :href="panelRoute('dns.zones')" class="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">{{ t('dns.cloudflare.back') }}</Link>
                    <button type="button" :disabled="form.processing || selectedCount === 0" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50" @click="submit">{{ form.processing ? t('dns.cloudflare.importing') : t('dns.cloudflare.importSelected', { count: selectedCount }) }}</button>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    zone: { type: Object, required: true },
    records: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
});

const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'];
const contentHints = computed(() => ({
    A: '203.0.113.10',
    AAAA: '2001:db8::10',
    CNAME: t('dns.recordsTable.hintCname'),
    MX: 'mail.example.com',
    TXT: t('dns.recordsTable.hintTxt'),
    NS: 'ns1.example.net',
    SRV: t('dns.recordsTable.hintSrv'),
    CAA: '0 issue "letsencrypt.org"',
    PTR: 'host.example.com',
}));
const ttlOptions = computed(() => [[60, t('dns.recordsTable.ttl.m1')], [300, t('dns.recordsTable.ttl.m5')], [1800, t('dns.recordsTable.ttl.m30')], [3600, t('dns.recordsTable.ttl.h1')], [14400, t('dns.recordsTable.ttl.h4')], [43200, t('dns.recordsTable.ttl.h12')], [86400, t('dns.recordsTable.ttl.d1')]]);
const typeColors = {
    A: 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    AAAA: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
    CNAME: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
    MX: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    TXT: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    NS: 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
};

const search = ref('');
const typeFilter = ref('');
const editingId = ref(null);
const adding = ref(false);
const saving = ref(false);
const message = ref('');

const blank = () => ({ record_id: '', powerdns_record_id: null, zone_domain: props.zone.domain, type: 'A', name: '', content: '', ttl: 3600, priority: null, status: 'active' });
const form = useForm(blank());
const priorityUsed = computed(() => ['MX', 'SRV'].includes(form.type));
const formError = computed(() => form.errors.name || form.errors.content || form.errors.type || form.errors.ttl || form.errors.priority || '');

const typeCounts = computed(() => props.records.reduce((counts, record) => ({ ...counts, [record.type]: (counts[record.type] || 0) + 1 }), {}));
const rows = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.records.filter((record) => (!typeFilter.value || record.type === typeFilter.value)
        && (!term || `${record.name} ${record.content}`.toLowerCase().includes(term)));
});
const ttlLabel = (ttl) => ttlOptions.value.find(([seconds]) => seconds === Number(ttl))?.[1] || `${ttl}s`;

const reset = () => {
    editingId.value = null;
    adding.value = false;
    form.clearErrors();
    form.defaults(blank());
    form.reset();
};
const startAdd = () => {
    reset();
    adding.value = true;
};
const startEdit = (record) => {
    reset();
    editingId.value = record.id;
    Object.assign(form, {
        record_id: record.id,
        powerdns_record_id: record.powerdns_record_id ?? null,
        zone_domain: props.zone.domain,
        type: record.type,
        name: record.name,
        content: record.content,
        ttl: Number(record.ttl),
        priority: record.priority ?? null,
        status: record.status,
    });
};

const refresh = () => router.reload({ only: ['zones', 'recordsByZone'], preserveState: true, preserveScroll: true });

const save = async () => {
    message.value = '';
    if (!editingId.value) {
        form.post(props.panelRoute('dns.records.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { message.value = t('dns.recordsTable.added'); reset(); },
        });
        return;
    }
    saving.value = true;
    form.clearErrors();
    try {
        const { data } = await window.axios.patch(props.panelRoute('dns.records.update', { id: editingId.value }), form.data(), { headers: { Accept: 'application/json' } });
        message.value = data?.message || t('dns.recordsTable.updated');
        reset();
        refresh();
    } catch (error) {
        Object.entries(error.response?.data?.errors || {}).forEach(([field, messages]) => form.setError(field, Array.isArray(messages) ? messages[0] : messages));
        if (!error.response?.data?.errors) message.value = error.response?.data?.message || t('dns.recordsTable.updateFailed');
    } finally {
        saving.value = false;
    }
};

const toggleStatus = (record) => {
    startEdit(record);
    form.status = record.status === 'active' ? 'disabled' : 'active';
    save();
};

const remove = (record) => {
    if (!confirm(t('dns.recordsTable.deleteConfirm', { type: record.type, name: record.name }))) return;
    router.delete(props.panelRoute('dns.records.destroy', { id: record.id }), { preserveScroll: true, preserveState: true });
};
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <input v-model="search" type="search" :placeholder="t('dns.recordsTable.searchPlaceholder')" class="w-64 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
            <div class="flex flex-wrap gap-1 text-xs">
                <button type="button" class="rounded-full border px-2.5 py-1" :class="!typeFilter ? 'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900' : 'border-slate-300 dark:border-slate-700'" @click="typeFilter = ''">{{ t('dns.recordsTable.all', { count: records.length }) }}</button>
                <button v-for="(count, type) in typeCounts" :key="type" type="button" class="rounded-full border px-2.5 py-1" :class="typeFilter === type ? 'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900' : 'border-slate-300 dark:border-slate-700'" @click="typeFilter = typeFilter === type ? '' : type">{{ type }} {{ count }}</button>
            </div>
            <button type="button" class="ml-auto rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="startAdd">+ {{ t('dns.recordsTable.add') }}</button>
        </div>
        <p v-if="message" class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ message }}</p>

        <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    <tr>
                        <th class="px-3 py-2.5">{{ t('dns.recordsTable.type') }}</th>
                        <th class="px-3 py-2.5">{{ t('dns.recordsTable.name') }}</th>
                        <th class="px-3 py-2.5">{{ t('dns.recordsTable.content') }}</th>
                        <th class="px-3 py-2.5">TTL</th>
                        <th class="px-3 py-2.5">{{ t('dns.recordsTable.statusCol') }}</th>
                        <th class="px-3 py-2.5 text-right">{{ t('dns.recordsTable.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="adding" class="border-t border-slate-200 bg-blue-50/50 align-top dark:border-slate-700 dark:bg-blue-500/5">
                        <td class="px-3 py-2"><select v-model="form.type" class="w-24 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"><option v-for="type in recordTypes" :key="type">{{ type }}</option></select></td>
                        <td class="px-3 py-2"><input v-model="form.name" type="text" :placeholder="t('dns.recordsTable.namePlaceholder')" class="w-40 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                        <td class="px-3 py-2">
                            <div class="flex gap-2">
                                <input v-if="priorityUsed" v-model.number="form.priority" type="number" min="0" max="65535" :placeholder="t('dns.recordsTable.priority')" class="w-20 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                <input v-model="form.content" type="text" :placeholder="contentHints[form.type]" class="w-full min-w-64 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" @keydown.enter.prevent="save" />
                            </div>
                            <p v-if="formError" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formError }}</p>
                        </td>
                        <td class="px-3 py-2"><select v-model.number="form.ttl" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"><option v-for="[seconds, label] in ttlOptions" :key="seconds" :value="seconds">{{ label }}</option></select></td>
                        <td class="px-3 py-2"><select v-model="form.status" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"><option value="active">{{ t('dns.recordsTable.status.active') }}</option><option value="disabled">{{ t('dns.recordsTable.status.disabled') }}</option></select></td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <button type="button" :disabled="form.processing" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-60" @click="save">{{ t('common.save') }}</button>
                            <button type="button" class="ml-1 rounded-md border border-slate-300 px-3 py-1.5 text-xs dark:border-slate-700" @click="reset">{{ t('common.cancel') }}</button>
                        </td>
                    </tr>

                    <tr v-for="record in rows" :key="record.id" class="border-t border-slate-200 align-top dark:border-slate-700" :class="record.status === 'disabled' ? 'opacity-60' : ''">
                        <template v-if="editingId === record.id">
                            <td class="px-3 py-2"><select v-model="form.type" class="w-24 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"><option v-for="type in recordTypes" :key="type">{{ type }}</option></select></td>
                            <td class="px-3 py-2"><input v-model="form.name" type="text" class="w-40 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                            <td class="px-3 py-2">
                                <div class="flex gap-2">
                                    <input v-if="priorityUsed" v-model.number="form.priority" type="number" min="0" max="65535" :placeholder="t('dns.recordsTable.priority')" class="w-20 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" />
                                    <input v-model="form.content" type="text" :placeholder="contentHints[form.type]" class="w-full min-w-64 rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800" @keydown.enter.prevent="save" />
                                </div>
                                <p v-if="formError" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ formError }}</p>
                            </td>
                            <td class="px-3 py-2"><select v-model.number="form.ttl" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"><option v-for="[seconds, label] in ttlOptions" :key="seconds" :value="seconds">{{ label }}</option><option v-if="!ttlOptions.some(([s]) => s === form.ttl)" :value="form.ttl">{{ form.ttl }}s</option></select></td>
                            <td class="px-3 py-2"><select v-model="form.status" class="rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800"><option value="active">{{ t('dns.recordsTable.status.active') }}</option><option value="disabled">{{ t('dns.recordsTable.status.disabled') }}</option></select></td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                <button type="button" :disabled="saving" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-60" @click="save">{{ t('common.save') }}</button>
                                <button type="button" class="ml-1 rounded-md border border-slate-300 px-3 py-1.5 text-xs dark:border-slate-700" @click="reset">{{ t('common.cancel') }}</button>
                            </td>
                        </template>
                        <template v-else>
                            <td class="px-3 py-2.5"><span class="rounded px-1.5 py-0.5 text-xs font-semibold" :class="typeColors[record.type] || 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'">{{ record.type }}</span></td>
                            <td class="px-3 py-2.5 font-medium">{{ record.name }}</td>
                            <td class="max-w-xl break-all px-3 py-2.5 font-mono text-xs"><span v-if="record.priority !== null && ['MX', 'SRV'].includes(record.type)" class="text-slate-500">{{ record.priority }} </span>{{ record.content }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-slate-500">{{ ttlLabel(record.ttl) }}</td>
                            <td class="px-3 py-2.5">
                                <button type="button" class="rounded-full px-2 py-0.5 text-xs" :class="record.status === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'" :title="record.status === 'active' ? t('dns.recordsTable.clickDisable') : t('dns.recordsTable.clickEnable')" @click="toggleStatus(record)">{{ t(`dns.recordsTable.status.${record.status}`) }}</button>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="startEdit(record)">{{ t('dns.recordsTable.edit') }}</button>
                                <button type="button" class="ml-1 rounded-md border border-red-300 px-2.5 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-950" @click="remove(record)">{{ t('dns.recordsTable.delete') }}</button>
                            </td>
                        </template>
                    </tr>

                    <tr v-if="!rows.length && !adding">
                        <td colspan="6" class="px-3 py-10 text-center text-sm text-slate-500">
                            {{ records.length ? t('dns.recordsTable.noMatch') : t('dns.recordsTable.empty') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    zone: { type: Object, required: true },
    scanUrl: { type: String, required: true },
    importUrl: { type: String, required: true },
});
const emit = defineEmits(['close']);

const scanning = ref(true);
const importing = ref(false);
const error = ref('');
const result = ref(null);
const rows = ref([]);

const shortName = (name) => (name === props.zone.domain ? '@' : name.replace(`.${props.zone.domain}`, ''));
// Records the zone already has, and one-off ACME tokens, start unticked.
const preselect = (record) => !record.exists && !record.name.startsWith('_acme-challenge.');

const scan = async () => {
    scanning.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(props.scanUrl, { headers: { Accept: 'application/json' } });
        result.value = data;
        rows.value = data.records.map((record) => ({ ...record, selected: preselect(record) }));
    } catch (failure) {
        error.value = failure.response?.status === 429
            ? t('dns.zoneScanImport.tooMany')
            : failure.response?.data?.message || t('dns.zoneScanImport.failed');
    } finally {
        scanning.value = false;
    }
};
onMounted(scan);

const selected = computed(() => rows.value.filter((row) => row.selected));
const allSelected = computed(() => rows.value.length > 0 && selected.value.length === rows.value.length);
const toggleAll = () => {
    const value = !allSelected.value;
    rows.value.forEach((row) => { row.selected = value; });
};

const importSelected = () => {
    importing.value = true;
    router.post(props.importUrl, {
        records: selected.value.map(({ name, type, content, priority, ttl }) => ({ name, type, content, priority, ttl })),
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('close'),
        onError: (errors) => { error.value = Object.values(errors)[0] || t('dns.zoneScanImport.importFailed'); },
        onFinish: () => { importing.value = false; },
    });
};
</script>

<template>
    <div class="fixed inset-0 z-[60] bg-slate-950/40" @click="emit('close')"></div>
    <div class="fixed inset-y-0 right-0 z-[70] flex w-full max-w-3xl flex-col bg-white shadow-2xl dark:bg-slate-900">
        <div class="flex items-center justify-between border-b border-slate-200 p-6 pb-4 dark:border-slate-700">
            <div>
                <h2 class="text-base font-semibold">{{ t('dns.zoneScanImport.title') }}</h2>
                <p class="text-xs text-slate-500">{{ t('dns.zoneScanImport.subtitle', { domain: zone.domain }) }}</p>
            </div>
            <button type="button" :title="t('common.close')" class="h-9 w-9 rounded-md border border-slate-300 text-lg dark:border-slate-700" @click="emit('close')">×</button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div v-if="scanning" class="py-16 text-center text-sm text-slate-500">
                <div class="mx-auto mb-3 h-6 w-6 animate-spin rounded-full border-2 border-slate-300 border-t-blue-600"></div>
                {{ t('dns.zoneScanImport.scanning', { domain: zone.domain }) }}
            </div>

            <template v-else>
                <p v-if="error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</p>

                <template v-if="result">
                    <div v-if="result.uses_our_nameservers" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                        {{ t('dns.zoneScanImport.ours', { domain: zone.domain }) }}
                    </div>
                    <div v-else-if="result.nameservers.length" class="mb-4 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">
                        <i18n-t keypath="dns.zoneScanImport.servedBy" scope="global"><template #list><strong>{{ result.nameservers.join(', ') }}</strong></template></i18n-t>
                        <template v-if="result.our_nameservers.length"> <i18n-t keypath="dns.zoneScanImport.afterImport" scope="global"><template #list><strong>{{ result.our_nameservers.join(', ') }}</strong></template></i18n-t></template>
                    </div>
                    <p v-if="result.wildcard" class="mb-4 text-xs text-slate-500">{{ t('dns.zoneScanImport.wildcard') }}</p>
                    <p class="mb-4 text-xs text-slate-500">{{ t('dns.zoneScanImport.limits') }}</p>

                    <div v-if="rows.length" class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs dark:bg-slate-800">
                                <tr>
                                    <th class="w-10 px-3 py-2"><input type="checkbox" :checked="allSelected" class="rounded border-slate-300" @change="toggleAll" /></th>
                                    <th class="px-3 py-2">{{ t('dns.zoneScanImport.type') }}</th>
                                    <th class="px-3 py-2">{{ t('dns.zoneScanImport.name') }}</th>
                                    <th class="px-3 py-2">{{ t('dns.zoneScanImport.content') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in rows" :key="index" class="cursor-pointer border-t border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60" @click="row.selected = !row.selected">
                                    <td class="px-3 py-2"><input v-model="row.selected" type="checkbox" class="rounded border-slate-300" @click.stop /></td>
                                    <td class="px-3 py-2 font-medium">{{ row.type }}</td>
                                    <td class="px-3 py-2">
                                        {{ shortName(row.name) }}
                                        <span v-if="row.exists" class="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-slate-800">{{ t('dns.zoneScanImport.exists') }}</span>
                                    </td>
                                    <td class="max-w-md break-all px-3 py-2 font-mono text-xs"><span v-if="row.priority !== null" class="text-slate-500">{{ row.priority }} </span>{{ row.content }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="py-10 text-center text-sm text-slate-500">{{ t('dns.zoneScanImport.none', { domain: zone.domain }) }}</p>
                </template>
            </template>
        </div>

        <div class="flex items-center gap-2 border-t border-slate-200 p-4 dark:border-slate-700">
            <button type="button" :disabled="scanning || importing || !selected.length" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="importSelected">
                {{ importing ? t('dns.zoneScanImport.importing') : selected.length === 1 ? t('dns.zoneScanImport.importOne') : t('dns.zoneScanImport.importMany', { count: selected.length }) }}
            </button>
            <button type="button" :disabled="scanning" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="scan">{{ t('dns.zoneScanImport.scanAgain') }}</button>
            <button type="button" class="ml-auto rounded-md px-4 py-2 text-sm text-slate-500 hover:text-slate-700" @click="emit('close')">{{ t('dns.zoneScanImport.skip') }}</button>
        </div>
    </div>
</template>

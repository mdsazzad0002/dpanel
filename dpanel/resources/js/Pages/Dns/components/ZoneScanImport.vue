<script setup>
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';

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
            ? 'Too many scans in a minute; wait a moment and try again.'
            : failure.response?.data?.message || 'The scan failed.';
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
        onSuccess: () => emit('close'),
        onError: (errors) => { error.value = Object.values(errors)[0] || 'Import failed.'; },
        onFinish: () => { importing.value = false; },
    });
};
</script>

<template>
    <div class="fixed inset-0 z-40 bg-slate-950/40" @click="emit('close')"></div>
    <div class="fixed inset-y-0 right-0 z-50 flex w-full max-w-3xl flex-col bg-white shadow-2xl dark:bg-slate-900">
        <div class="flex items-center justify-between border-b border-slate-200 p-6 pb-4 dark:border-slate-700">
            <div>
                <h2 class="text-base font-semibold">Import existing DNS records</h2>
                <p class="text-xs text-slate-500">Records {{ zone.domain }} has today, read from public DNS</p>
            </div>
            <button type="button" title="Close" class="h-9 w-9 rounded-md border border-slate-300 text-lg dark:border-slate-700" @click="emit('close')">×</button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div v-if="scanning" class="py-16 text-center text-sm text-slate-500">
                <div class="mx-auto mb-3 h-6 w-6 animate-spin rounded-full border-2 border-slate-300 border-t-blue-600"></div>
                Scanning {{ zone.domain }} and its common subdomains…
            </div>

            <template v-else>
                <p v-if="error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">{{ error }}</p>

                <template v-if="result">
                    <div v-if="result.uses_our_nameservers" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                        {{ zone.domain }} already uses this server's nameservers, so public DNS only shows what is in this zone. Scan before you switch nameservers.
                    </div>
                    <div v-else-if="result.nameservers.length" class="mb-4 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">
                        Currently served by <strong>{{ result.nameservers.join(', ') }}</strong>.
                        <template v-if="result.our_nameservers.length"> After importing, change the domain's nameservers at your registrar to <strong>{{ result.our_nameservers.join(', ') }}</strong>.</template>
                    </div>
                    <p v-if="result.wildcard" class="mb-4 text-xs text-slate-500">This domain has a wildcard (*) record. Subdomains that only answer through it are covered by the * record and not listed separately.</p>
                    <p class="mb-4 text-xs text-slate-500">Like any scan, this finds the apex and common names only. Check your old provider for records with unusual names and add them by hand or with a zone file.</p>

                    <div v-if="rows.length" class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs dark:bg-slate-800">
                                <tr>
                                    <th class="w-10 px-3 py-2"><input type="checkbox" :checked="allSelected" class="rounded border-slate-300" @change="toggleAll" /></th>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Name</th>
                                    <th class="px-3 py-2">Content</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in rows" :key="index" class="cursor-pointer border-t border-slate-200 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60" @click="row.selected = !row.selected">
                                    <td class="px-3 py-2"><input v-model="row.selected" type="checkbox" class="rounded border-slate-300" @click.stop /></td>
                                    <td class="px-3 py-2 font-medium">{{ row.type }}</td>
                                    <td class="px-3 py-2">
                                        {{ shortName(row.name) }}
                                        <span v-if="row.exists" class="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-slate-800">already in zone</span>
                                    </td>
                                    <td class="max-w-md break-all px-3 py-2 font-mono text-xs"><span v-if="row.priority !== null" class="text-slate-500">{{ row.priority }} </span>{{ row.content }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="py-10 text-center text-sm text-slate-500">No records were found for {{ zone.domain }}.</p>
                </template>
            </template>
        </div>

        <div class="flex items-center gap-2 border-t border-slate-200 p-4 dark:border-slate-700">
            <button type="button" :disabled="scanning || importing || !selected.length" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="importSelected">
                {{ importing ? 'Importing…' : `Import ${selected.length} record${selected.length === 1 ? '' : 's'}` }}
            </button>
            <button type="button" :disabled="scanning" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="scan">Scan again</button>
            <button type="button" class="ml-auto rounded-md px-4 py-2 text-sm text-slate-500 hover:text-slate-700" @click="emit('close')">Skip</button>
        </div>
    </div>
</template>

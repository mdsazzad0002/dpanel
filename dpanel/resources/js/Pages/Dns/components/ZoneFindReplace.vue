<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    zones: { type: Array, default: () => [] },
    zoneId: { type: String, default: '' },
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['close']);

const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'];

const form = ref({ find: '', replace: '', mode: 'word', match_case: false, types: [], zone: props.zoneId });
const changes = ref(null);
const limited = ref(false);
const picked = ref(new Set());
const errors = ref({});
const loading = ref(false);
const applying = ref(false);

const zoneOptions = computed(() => props.zones.filter((zone) => zone.zone_uuid).sort((a, b) => a.domain.localeCompare(b.domain)));
const valid = computed(() => (changes.value || []).filter((change) => !change.error));
const invalid = computed(() => (changes.value || []).filter((change) => change.error));
const zoneCount = computed(() => new Set(valid.value.filter((change) => picked.value.has(change.id)).map((change) => change.zone)).size);

const payload = () => ({
    find: form.value.find,
    replace: form.value.replace,
    mode: form.value.mode,
    match_case: form.value.match_case,
    types: form.value.types,
    zones: form.value.zone ? [form.value.zone] : [],
});

// A preview only holds for the search it came from.
watch(form, () => { changes.value = null; }, { deep: true });

const preview = async () => {
    loading.value = true;
    errors.value = {};
    try {
        const { data } = await window.axios.post(props.panelRoute('dns.zones.find-replace.preview'), payload(), { headers: { Accept: 'application/json' } });
        changes.value = data.changes;
        limited.value = data.limited;
        picked.value = new Set(data.changes.filter((change) => !change.error).map((change) => change.id));
    } catch (error) {
        errors.value = error.response?.data?.errors || { find: [error.response?.data?.message || t('dns.findReplace.previewFailed')] };
    } finally {
        loading.value = false;
    }
};

const toggle = (id) => {
    const next = new Set(picked.value);
    next.has(id) ? next.delete(id) : next.add(id);
    picked.value = next;
};
const toggleAll = () => {
    picked.value = picked.value.size === valid.value.length ? new Set() : new Set(valid.value.map((change) => change.id));
};

const apply = () => {
    const count = picked.value.size;
    if (!count || !confirm(t('dns.findReplace.applyConfirm', { records: count === 1 ? t('dns.findReplace.oneRecord') : t('dns.findReplace.manyRecords', { count }), zones: zoneCount.value === 1 ? t('dns.findReplace.oneZone') : t('dns.findReplace.manyZones', { count: zoneCount.value }) }))) return;
    router.post(props.panelRoute('dns.zones.find-replace'), { ...payload(), record_ids: [...picked.value] }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { applying.value = true; },
        onFinish: () => { applying.value = false; },
        onError: (bag) => { errors.value = Object.fromEntries(Object.entries(bag).map(([key, value]) => [key, [value]])); },
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <div class="fixed inset-0 z-[60] bg-slate-950/40" @click="emit('close')"></div>
    <div class="fixed inset-y-0 right-0 z-[70] flex w-full max-w-3xl flex-col bg-white shadow-2xl dark:bg-slate-900">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4 dark:border-slate-700">
            <div>
                <h2 class="text-base font-semibold">{{ t('dns.findReplace.title') }}</h2>
                <p class="text-xs text-slate-500">{{ t('dns.findReplace.subtitle') }}</p>
            </div>
            <button type="button" :title="t('common.close')" class="h-9 w-9 rounded-md border border-slate-300 text-lg dark:border-slate-700" @click="emit('close')">×</button>
        </div>

        <form class="grid gap-4 border-b border-slate-200 px-6 py-4 dark:border-slate-700" @submit.prevent="preview">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm">{{ t('dns.findReplace.find') }}</label>
                    <input v-model="form.find" type="text" spellcheck="false" placeholder="203.0.113.10" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="errors.find" class="mt-1 text-xs text-red-600">{{ errors.find[0] }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('dns.findReplace.replaceWith') }}</label>
                    <input v-model="form.replace" type="text" spellcheck="false" placeholder="198.51.100.20" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" />
                    <p v-if="errors.replace" class="mt-1 text-xs text-red-600">{{ errors.replace[0] }}</p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm">{{ t('dns.findReplace.zones') }}</label>
                    <select v-model="form.zone" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ t('dns.findReplace.allZones', { count: zoneOptions.length }) }}</option>
                        <option v-for="zone in zoneOptions" :key="zone.zone_uuid" :value="zone.zone_uuid">{{ zone.domain }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">{{ t('dns.findReplace.match') }}</label>
                    <select v-model="form.mode" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="word">{{ t('dns.findReplace.modeWord') }}</option>
                        <option value="contains">{{ t('dns.findReplace.modeContains') }}</option>
                        <option value="exact">{{ t('dns.findReplace.modeExact') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <span class="mb-1 block text-sm">{{ t('dns.findReplace.types') }} <span class="text-xs text-slate-500">{{ t('dns.findReplace.typesHelp') }}</span></span>
                <div class="flex flex-wrap gap-3 text-sm">
                    <label v-for="type in TYPES" :key="type" class="flex items-center gap-1.5">
                        <input v-model="form.types" type="checkbox" :value="type" class="rounded border-slate-300" />{{ type }}
                    </label>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.match_case" type="checkbox" class="rounded border-slate-300" />{{ t('dns.findReplace.matchCase') }}
                </label>
                <button type="submit" :disabled="loading || !form.find.trim()" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                    {{ loading ? t('dns.findReplace.searching') : t('dns.findReplace.findMatches') }}
                </button>
            </div>
        </form>

        <div class="flex-1 overflow-y-auto px-6 py-4">
            <p v-if="changes === null" class="text-sm text-slate-500">{{ t('dns.findReplace.hint') }}</p>
            <p v-else-if="!changes.length" class="text-sm text-slate-500">{{ t('dns.findReplace.noMatch', { term: form.find }) }}</p>
            <template v-else>
                <p v-if="limited" class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ t('dns.findReplace.limited', { count: changes.length }) }}</p>
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-3"><input type="checkbox" :checked="valid.length > 0 && picked.size === valid.length" :disabled="!valid.length" class="rounded border-slate-300" @change="toggleAll" /></th>
                            <th class="py-2 pr-3">{{ t('dns.findReplace.record') }}</th>
                            <th class="py-2">{{ t('dns.findReplace.beforeAfter') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="change in [...valid, ...invalid]" :key="change.id" class="border-t border-slate-200 align-top dark:border-slate-800" :class="change.error ? 'opacity-70' : ''">
                            <td class="py-2 pr-3"><input type="checkbox" :checked="picked.has(change.id)" :disabled="!!change.error" class="rounded border-slate-300" @change="toggle(change.id)" /></td>
                            <td class="py-2 pr-3">
                                <span class="mr-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium dark:bg-slate-800">{{ change.type }}</span>
                                <span class="break-all">{{ change.name }}</span>
                            </td>
                            <td class="py-2 font-mono text-xs">
                                <div class="break-all text-red-700 line-through decoration-red-300 dark:text-red-300">{{ change.before }}</div>
                                <div v-if="!change.error" class="break-all text-emerald-700 dark:text-emerald-300">{{ change.after }}</div>
                                <div v-else class="font-sans text-red-600">{{ t('dns.findReplace.cantChange', { error: change.error }) }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </div>

        <div v-if="changes?.length" class="flex items-center justify-between gap-3 border-t border-slate-200 px-6 py-4 dark:border-slate-700">
            <span class="text-sm text-slate-500">{{ t('dns.findReplace.selected', { picked: picked.size, total: changes.length }) }}<template v-if="invalid.length"> · {{ t('dns.findReplace.invalidCount', { count: invalid.length }) }}</template></span>
            <div class="flex gap-2">
                <p v-if="errors.record_ids" class="self-center text-xs text-red-600">{{ errors.record_ids[0] }}</p>
                <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('close')">{{ t('common.cancel') }}</button>
                <button type="button" :disabled="applying || !picked.size" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" @click="apply">
                    {{ applying ? t('dns.findReplace.replacing') : t('dns.findReplace.replaceN', { count: picked.size }) }}
                </button>
            </div>
        </div>
    </div>
</template>

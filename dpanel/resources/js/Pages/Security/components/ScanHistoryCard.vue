<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    scans: { type: Array, default: () => [] },
    panelRoute: { type: Function, required: true },
});

const emit = defineEmits(['deleted']);

const selected = ref(new Set());
const deleting = ref(false);
const message = ref(null);

// Running scans cannot go, and the newest completed scan of each type feeds the score.
const selectable = (scan) => !scan.protected && !['queued', 'running'].includes(scan.status);
const selectableIds = computed(() => props.scans.filter(selectable).map((scan) => scan.id));
const allSelected = computed(() => selectableIds.value.length > 0 && selectableIds.value.every((id) => selected.value.has(id)));

const toggle = (id) => {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
};
const toggleAll = () => {
    selected.value = allSelected.value ? new Set() : new Set(selectableIds.value);
};

watch(() => props.scans, () => {
    const ids = new Set(selectableIds.value);
    selected.value = new Set([...selected.value].filter((id) => ids.has(id)));
});

const deleteSelected = async () => {
    const ids = [...selected.value];
    if (!confirm(`Delete ${ids.length} scan${ids.length === 1 ? '' : 's'} from the history? Their findings stay.`)) return;
    deleting.value = true;
    message.value = null;
    try {
        const { data } = await axios.delete(props.panelRoute('security.center.scans.destroy'), { data: { ids } });
        selected.value = new Set();
        message.value = { type: 'success', text: data.message };
        emit('deleted');
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not delete the scans.' };
    } finally {
        deleting.value = false;
    }
};

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold">Scan history</h2>
            <div v-if="selected.size" class="flex items-center gap-2">
                <button type="button" :disabled="deleting" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-60" @click="deleteSelected">
                    {{ deleting ? 'Deleting…' : `Delete ${selected.size}` }}
                </button>
                <button type="button" :disabled="deleting" class="rounded-md px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-100 disabled:opacity-60 dark:text-slate-300 dark:hover:bg-slate-800" @click="selected = new Set()">
                    Clear
                </button>
            </div>
        </div>

        <p v-if="message" :class="message.type === 'success' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" class="mt-2 text-xs">{{ message.text }}</p>

        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="w-8 px-3 py-2">
                            <input type="checkbox" :checked="allSelected" :disabled="!selectableIds.length" title="Select all scans that can be deleted" class="rounded border-slate-300 dark:border-slate-600" @change="toggleAll">
                        </th>
                        <th class="px-3 py-2">Target</th>
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Files</th>
                        <th class="px-3 py-2">Threats</th>
                        <th class="px-3 py-2">Risk</th>
                        <th class="px-3 py-2">Finished</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="scan in scans" :key="scan.id" :class="selected.has(scan.id) ? 'bg-blue-50/60 dark:bg-blue-950/30' : ''" class="border-t border-slate-200 align-top dark:border-slate-800">
                        <td class="px-3 py-2">
                            <input
                                type="checkbox"
                                :checked="selected.has(scan.id)"
                                :disabled="!selectable(scan)"
                                :title="scan.protected ? 'Latest scan of this type: the security score uses it' : (selectable(scan) ? '' : 'Still running')"
                                class="rounded border-slate-300 disabled:opacity-40 dark:border-slate-600"
                                @change="toggle(scan.id)"
                            >
                        </td>
                        <td class="px-3 py-2">
                            {{ scan.target }}
                            <span v-if="scan.protected" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">latest</span>
                        </td>
                        <td class="px-3 py-2 capitalize">{{ scan.scan_type }}</td>
                        <td class="px-3 py-2">
                            <span class="capitalize" :class="{ 'text-red-600': scan.status === 'failed', 'text-emerald-600': scan.status === 'completed', 'text-amber-600': ['queued', 'running'].includes(scan.status) }">{{ scan.status }}</span>
                            <p v-if="scan.error" class="max-w-xs text-xs text-red-600">{{ scan.error }}</p>
                            <p v-else-if="scan.clamav?.error" class="max-w-xs text-xs text-amber-600">ClamAV: {{ scan.clamav.error }}</p>
                            <p v-if="scan.truncated" class="text-xs text-amber-600">File limit reached; not every file was scanned.</p>
                        </td>
                        <td class="px-3 py-2">{{ scan.files_scanned }}</td>
                        <td class="px-3 py-2">{{ scan.threats_found }}</td>
                        <td class="px-3 py-2">{{ scan.risk_score ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ formatDate(scan.completed_at) }}</td>
                    </tr>
                    <tr v-if="scans.length === 0">
                        <td colspan="8" class="px-3 py-4 text-center text-slate-500">No scans yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

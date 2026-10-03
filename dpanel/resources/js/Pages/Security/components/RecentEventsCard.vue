<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    events: { type: Array, default: () => [] },
    severityStyles: { type: Object, required: true },
    panelRoute: { type: Function, required: true },
});

const emit = defineEmits(['deleted']);

const selected = ref(new Set());
const deleting = ref(false);
const message = ref(null);

const allSelected = computed(() => props.events.length > 0 && props.events.every((event) => selected.value.has(event.id)));

const toggle = (id) => {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
};
const toggleAll = () => {
    selected.value = allSelected.value ? new Set() : new Set(props.events.map((event) => event.id));
};

// Fresh events arrive after a delete; drop ids that are no longer listed.
watch(() => props.events, (events) => {
    const ids = new Set(events.map((event) => event.id));
    selected.value = new Set([...selected.value].filter((id) => ids.has(id)));
});

const deleteSelected = async () => {
    const ids = [...selected.value];
    if (!confirm(`Delete ${ids.length} event${ids.length === 1 ? '' : 's'}?`)) return;
    deleting.value = true;
    message.value = null;
    try {
        const { data } = await axios.delete(props.panelRoute('security.center.events.destroy'), { data: { ids } });
        selected.value = new Set();
        message.value = { type: 'success', text: data.message };
        emit('deleted');
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not delete the events.' };
    } finally {
        deleting.value = false;
    }
};

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <label class="flex items-center gap-2">
                <input type="checkbox" :checked="allSelected" :disabled="!events.length" title="Select all events" class="rounded border-slate-300 dark:border-slate-600" @change="toggleAll">
                <h2 class="text-base font-semibold">Recent security events</h2>
            </label>
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

        <ul class="mt-4 divide-y divide-slate-100 text-sm dark:divide-slate-800">
            <li v-for="event in events" :key="event.id" :class="selected.has(event.id) ? 'bg-blue-50/60 dark:bg-blue-950/30' : ''" class="flex items-start gap-2 py-2">
                <input type="checkbox" :checked="selected.has(event.id)" class="mt-0.5 rounded border-slate-300 dark:border-slate-600" @change="toggle(event.id)">
                <span class="rounded-full px-2 py-0.5 text-xs capitalize" :class="severityStyles[event.severity] || severityStyles.info">{{ event.severity }}</span>
                <div class="min-w-0">
                    <p>{{ event.message }}</p>
                    <p class="text-xs text-slate-500">{{ formatDate(event.created_at) }}</p>
                </div>
            </li>
            <li v-if="events.length === 0" class="py-4 text-center text-slate-500">No events yet.</li>
        </ul>
    </div>
</template>

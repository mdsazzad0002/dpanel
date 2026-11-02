<script setup>
import { computed, ref } from 'vue';
import { fmtBytes, fmtProgress, percent } from './format';

const props = defineProps({
    catalog: { type: Array, default: () => [] },
    categories: { type: Object, default: () => ({}) },
    installed: { type: Array, default: () => [] },
    pulls: { type: Object, default: () => ({}) },
    totalMemory: { type: Number, default: 0 },
    busy: { type: Boolean, default: false },
});
defineEmits(['pull', 'cancel']);

const active = ref('all');
const search = ref('');

const tabs = computed(() => [
    { key: 'all', label: 'All', icon: 'bi-grid', count: props.catalog.length },
    ...Object.entries(props.categories).map(([key, c]) => ({
        key, ...c, count: props.catalog.filter((m) => m.category === key).length,
    })),
]);

// Smallest first inside a group, so what fits this server comes first.
const groups = computed(() => {
    const q = search.value.trim().toLowerCase();
    const match = (m) => !q || `${m.name} ${m.id} ${m.note}`.toLowerCase().includes(q);
    const keys = active.value === 'all' ? Object.keys(props.categories) : [active.value];
    const out = keys.map((key) => ({
        key,
        ...props.categories[key],
        items: props.catalog.filter((m) => m.category === key && match(m)).sort((a, b) => a.size_bytes - b.size_bytes),
    }));
    // Entries without a known category still show under "All".
    if (active.value === 'all') {
        const rest = props.catalog.filter((m) => !props.categories[m.category] && match(m));
        if (rest.length) out.push({ key: 'other', label: 'Other', items: rest });
    }
    return out.filter((g) => g.items.length);
});

const stateOf = (item) => {
    if (props.installed.includes(item.id)) return 'installed';
    if (props.pulls[item.id]?.state === 'downloading') return 'downloading';
    return null;
};
// Weights plus the context's working memory must fit next to everything else.
const tooBig = (item) => props.totalMemory > 0 && item.size_bytes * 1.3 > props.totalMemory * 0.8;
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
        <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-sm font-semibold">Model catalog</h2>
                <p class="mt-0.5 text-xs text-slate-500">Quantized (Q4) models that run on a CPU, grouped by what they're good at. Smaller is faster; larger answers better.</p>
            </div>
            <input v-model="search" type="search" placeholder="Search models…"
                class="rounded-md border border-slate-300 px-2 py-1 text-sm md:w-56 dark:border-slate-600 dark:bg-slate-900" />
        </div>

        <div class="mt-3 flex flex-wrap gap-1.5">
            <button v-for="t in tabs" :key="t.key" @click="active = t.key"
                class="rounded-full border px-3 py-1 text-xs"
                :class="active === t.key
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                    : 'border-slate-300 text-slate-600 hover:bg-slate-100 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700'">
                <i class="bi" :class="t.icon"></i> {{ t.label }} <span class="text-slate-400">{{ t.count }}</span>
            </button>
        </div>

        <p v-if="!groups.length" class="mt-4 text-xs text-slate-500">No models match "{{ search }}".</p>

        <div v-for="g in groups" :key="g.key" class="mt-4">
            <div class="flex items-baseline gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                    <i class="bi" :class="g.icon"></i> {{ g.label }}
                </h3>
                <span v-if="g.note" class="text-xs text-slate-400">{{ g.note }}</span>
            </div>
            <div class="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="item in g.items" :key="item.id"
                    class="flex flex-col rounded-md border p-3"
                    :class="item.recommended ? 'border-indigo-300 dark:border-indigo-700' : 'border-slate-200 dark:border-slate-700'">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-sm font-medium">{{ item.name }}</div>
                        <span class="shrink-0 text-xs text-slate-400">{{ fmtBytes(item.size_bytes) }}</span>
                    </div>
                    <span v-if="item.recommended" class="mt-1 w-fit rounded bg-indigo-100 px-1.5 text-[11px] text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">Recommended</span>
                    <p class="mt-1 flex-1 text-xs text-slate-500">{{ item.note }}</p>
                    <p v-if="tooBig(item)" class="mt-1 text-xs text-amber-600">Likely too large for this server's RAM.</p>
                    <div class="mt-2">
                        <span v-if="stateOf(item) === 'installed'" class="text-xs text-emerald-600"><i class="bi bi-check2"></i> Installed</span>
                        <div v-else-if="stateOf(item) === 'downloading'">
                            <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
                                <span>{{ percent(pulls[item.id]) ?? 0 }}% <span class="text-slate-400">{{ fmtProgress(pulls[item.id]) }}</span></span>
                                <button @click="$emit('cancel', item.id)" class="text-red-500 hover:text-red-700" title="Cancel download">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </button>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded bg-indigo-500 transition-all" :style="{ width: (percent(pulls[item.id]) ?? 0) + '%' }"></div>
                            </div>
                        </div>
                        <button v-else @click="$emit('pull', { url: item.url, id: item.id })" :disabled="busy"
                            class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">
                            <i class="bi bi-download"></i> Download
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

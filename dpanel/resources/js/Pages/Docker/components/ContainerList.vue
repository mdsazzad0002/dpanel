<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    containers: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    busy: { type: String, default: '' },
});

defineEmits(['action', 'logs']);

const search = ref('');

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.containers.filter((c) => !needle || `${c.name} ${c.image} ${c.id}`.toLowerCase().includes(needle));
});

const stateClass = (state) => ({
    running: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    restarting: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    paused: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
}[state] || 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300');
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold">Containers</h2>
            <input v-model="search" type="search" placeholder="Find a container" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-64 dark:border-slate-700 dark:bg-slate-800" />
        </div>

        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-3 py-2">Name</th>
                        <th class="px-3 py-2">Image</th>
                        <th class="px-3 py-2">State</th>
                        <th class="px-3 py-2">Ports</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in rows" :key="c.id" class="border-t border-slate-200 align-top dark:border-slate-800">
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ c.name }}</div>
                            <div class="font-mono text-[11px] text-slate-500">{{ c.id }} · {{ c.created }}</div>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ c.image }}</td>
                        <td class="px-3 py-2">
                            <span :class="stateClass(c.state)" class="rounded px-1.5 py-0.5 text-[11px] font-semibold">{{ c.state }}</span>
                            <div class="mt-1 text-xs text-slate-500">{{ c.status }}</div>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ c.ports || '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <div class="inline-flex gap-1">
                                <button v-if="c.state !== 'running'" type="button" :disabled="busy === c.id" class="rounded-md border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 disabled:opacity-40 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-950" @click="$emit('action', 'start', c)">Start</button>
                                <button v-else type="button" :disabled="busy === c.id" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('action', 'stop', c)">Stop</button>
                                <button type="button" :disabled="busy === c.id" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('action', 'restart', c)">Restart</button>
                                <button type="button" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('logs', c)">Logs</button>
                                <button type="button" :disabled="busy === c.id" class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-40 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950" @click="$emit('action', 'remove', c)">Remove</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500">
                            {{ loading ? 'Loading…' : (search ? 'No container matches.' : 'No containers yet. Run one above.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script setup>
import { fmtBytes } from './format';

defineProps({
    models: { type: Array, default: () => [] },
    busy: { type: Object, default: () => ({}) },
});
defineEmits(['load', 'unload', 'remove']);
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <div class="border-b border-slate-200 px-4 py-3 text-sm font-semibold dark:border-slate-700">Installed models</div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                    <tr>
                        <th class="px-4 py-3 font-medium">Model</th>
                        <th class="px-4 py-3 font-medium">Size</th>
                        <th class="px-4 py-3 font-medium">State</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <tr v-if="!models.length">
                        <td colspan="4" class="px-4 py-6 text-center text-sm text-slate-400">No models yet. Download one below.</td>
                    </tr>
                    <tr v-for="m in models" :key="m.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                        <td class="px-4 py-3 font-mono text-xs">{{ m.id }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ fmtBytes(m.size_bytes) }}</td>
                        <td class="px-4 py-3">
                            <span v-if="busy[m.id]" class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">{{ busy[m.id] }}…</span>
                            <span v-else class="rounded px-2 py-0.5 text-xs" :class="m.loaded ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                                {{ m.loaded ? 'In memory' : 'On disk' }}
                            </span>
                        </td>
                        <td class="space-x-1 whitespace-nowrap px-4 py-3 text-right">
                            <button v-if="!m.loaded" @click="$emit('load', m)" :disabled="!!busy[m.id]"
                                title="Load into memory now, so the first chat doesn't wait"
                                class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">Load</button>
                            <button v-else @click="$emit('unload', m)" :disabled="!!busy[m.id]"
                                title="Free its memory; it loads again on the next request"
                                class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">Unload</button>
                            <button @click="$emit('remove', m)" :disabled="!!busy[m.id]"
                                class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:hover:bg-red-950">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

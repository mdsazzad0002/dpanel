<script setup>
import { fmtBytes } from './format';

const props = defineProps({
    models: { type: Array, default: () => [] },
    busy: { type: Object, default: () => ({}) },
    tunes: { type: Object, default: () => ({}) },
});
defineEmits(['load', 'unload', 'remove', 'tune']);

const tags = (m) => {
    const d = m.profile?.detected;
    if (!d) return [];
    return [
        ...(d.capabilities || []).map((c) => ({ chat: 'Chat', code: 'Code' }[c] || c)),
        ...(d.thinks ? ['Thinks first'] : []),
    ];
};
const testing = (m) => ['queued', 'running'].includes(props.tunes[m.id]?.state);
// Where the self-test put it; 999 means every layer.
const device = (m) => {
    const layers = m.profile?.gpu_layers;
    if (layers == null) return null;
    if (layers === 0) return 'CPU';
    return layers >= (m.profile?.detected?.n_layer || 999) ? 'GPU' : `GPU ${layers}/${m.profile.detected.n_layer} layers`;
};
const speed = (m) => m.profile?.speed;
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <div class="border-b border-slate-200 px-4 py-3 text-sm font-semibold dark:border-slate-700">Installed models</div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                    <tr>
                        <th class="px-4 py-3 font-medium">Model</th>
                        <th class="px-4 py-3 font-medium">Good for</th>
                        <th class="px-4 py-3 font-medium">Speed on this server</th>
                        <th class="px-4 py-3 font-medium">Size</th>
                        <th class="px-4 py-3 font-medium">State</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <tr v-if="!models.length">
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-400">No models yet. Download one below.</td>
                    </tr>
                    <tr v-for="m in models" :key="m.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                        <td class="px-4 py-3 font-mono text-xs">{{ m.id }}</td>
                        <td class="px-4 py-3">
                            <span v-for="t in tags(m)" :key="t" class="mr-1 rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">{{ t }}</span>
                            <span v-if="!tags(m).length" class="text-xs text-slate-400">Detected on first load</span>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <template v-if="testing(m)">
                                <span class="text-amber-600"><i class="bi bi-speedometer2"></i> {{ tunes[m.id].step }}…</span>
                            </template>
                            <template v-else-if="speed(m)">
                                <div><span class="font-medium">{{ speed(m).gen_tps.toFixed(1) }}</span> tok/s writing · <span class="font-medium">{{ Math.round(speed(m).prompt_tps) }}</span> tok/s reading</div>
                                <div class="text-slate-400">
                                    <template v-if="device(m)">{{ device(m) }} · </template>
                                    <template v-if="speed(m).samples">learned from {{ speed(m).samples }} chats</template>
                                    <template v-else>from speed test</template>
                                </div>
                            </template>
                            <span v-else class="text-slate-400">Not measured yet</span>
                            <div v-if="tunes[m.id]?.state === 'failed'" class="text-red-600">Test failed: {{ tunes[m.id].error }}</div>
                            <div v-else-if="tunes[m.id]?.state === 'done'" class="text-emerald-600">{{ tunes[m.id].step }}</div>
                        </td>
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
                            <button @click="$emit('tune', m)" :disabled="!!busy[m.id] || testing(m)"
                                title="Try GPU and CPU settings on this server and keep the fastest (takes a minute or two)"
                                class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700"><i class="bi bi-speedometer2"></i> Test</button>
                            <button @click="$emit('remove', m)" :disabled="!!busy[m.id]"
                                class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:hover:bg-red-950">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

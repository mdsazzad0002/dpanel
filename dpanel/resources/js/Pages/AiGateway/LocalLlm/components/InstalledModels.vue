<script setup>
import { fmtBytes } from './format';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
        ...(d.capabilities || []).map((c) => ({ chat: t('aiGateway.localLlm.installed.tagChat'), code: t('aiGateway.localLlm.installed.tagCode') }[c] || c)),
        ...(d.thinks ? [t('aiGateway.localLlm.installed.tagThinks')] : []),
    ];
};
const testing = (m) => ['queued', 'running'].includes(props.tunes[m.id]?.state);
// Where the self-test put it; 999 means every layer.
const device = (m) => {
    const layers = m.profile?.gpu_layers;
    if (layers == null) return null;
    if (layers === 0) return 'CPU';
    return layers >= (m.profile?.detected?.n_layer || 999) ? 'GPU' : t('aiGateway.localLlm.installed.gpuLayers', { layers, total: m.profile.detected.n_layer });
};
const speed = (m) => m.profile?.speed;
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <div class="border-b border-slate-200 px-4 py-3 text-sm font-semibold dark:border-slate-700">{{ t('aiGateway.localLlm.installed.title') }}</div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('aiGateway.localLlm.installed.model') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('aiGateway.localLlm.installed.goodFor') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('aiGateway.localLlm.installed.speed') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('aiGateway.localLlm.installed.size') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('aiGateway.localLlm.installed.state') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('aiGateway.localLlm.installed.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <tr v-if="!models.length">
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-400">{{ t('aiGateway.localLlm.installed.empty') }}</td>
                    </tr>
                    <tr v-for="m in models" :key="m.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                        <td class="px-4 py-3 font-mono text-xs">{{ m.id }}</td>
                        <td class="px-4 py-3">
                            <span v-for="tag in tags(m)" :key="tag" class="mr-1 rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">{{ tag }}</span>
                            <span v-if="!tags(m).length" class="text-xs text-slate-400">{{ t('aiGateway.localLlm.installed.detectedOnLoad') }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <template v-if="testing(m)">
                                <span class="text-amber-600"><i class="bi bi-speedometer2"></i> {{ tunes[m.id].step }}…</span>
                            </template>
                            <template v-else-if="speed(m)">
                                <div><i18n-t keypath="aiGateway.localLlm.installed.tps" scope="global"><template #gen><span class="font-medium">{{ speed(m).gen_tps.toFixed(1) }}</span></template><template #prompt><span class="font-medium">{{ Math.round(speed(m).prompt_tps) }}</span></template></i18n-t></div>
                                <div class="text-slate-400">
                                    <template v-if="device(m)">{{ device(m) }} · </template>
                                    <template v-if="speed(m).samples">{{ t('aiGateway.localLlm.installed.learned', { count: speed(m).samples }) }}</template>
                                    <template v-else>{{ t('aiGateway.localLlm.installed.fromTest') }}</template>
                                </div>
                            </template>
                            <span v-else class="text-slate-400">{{ t('aiGateway.localLlm.installed.notMeasured') }}</span>
                            <div v-if="tunes[m.id]?.state === 'failed'" class="text-red-600">{{ t('aiGateway.localLlm.installed.testFailed', { error: tunes[m.id].error }) }}</div>
                            <div v-else-if="tunes[m.id]?.state === 'done'" class="text-emerald-600">{{ tunes[m.id].step }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ fmtBytes(m.size_bytes) }}</td>
                        <td class="px-4 py-3">
                            <span v-if="busy[m.id]" class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">{{ busy[m.id] }}…</span>
                            <span v-else class="rounded px-2 py-0.5 text-xs" :class="m.loaded ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                                {{ m.loaded ? t('aiGateway.localLlm.installed.inMemory') : t('aiGateway.localLlm.installed.onDisk') }}
                            </span>
                        </td>
                        <td class="space-x-1 whitespace-nowrap px-4 py-3 text-right">
                            <button v-if="!m.loaded" @click="$emit('load', m)" :disabled="!!busy[m.id]"
                                :title="t('aiGateway.localLlm.installed.loadHint')"
                                class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">{{ t('aiGateway.localLlm.installed.load') }}</button>
                            <button v-else @click="$emit('unload', m)" :disabled="!!busy[m.id]"
                                :title="t('aiGateway.localLlm.installed.unloadHint')"
                                class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">{{ t('aiGateway.localLlm.installed.unload') }}</button>
                            <button @click="$emit('tune', m)" :disabled="!!busy[m.id] || testing(m)"
                                :title="t('aiGateway.localLlm.installed.testHint')"
                                class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700"><i class="bi bi-speedometer2"></i> {{ t('aiGateway.localLlm.installed.test') }}</button>
                            <button @click="$emit('remove', m)" :disabled="!!busy[m.id]"
                                class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:hover:bg-red-950">{{ t('aiGateway.localLlm.installed.delete') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { fmtBytes, fmtIdle } from './format';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    configured: { type: Boolean, default: false },
    online: { type: Boolean, default: false },
    status: { type: Object, default: null },
    baseUrl: { type: String, default: '' },
    service: { type: Object, default: null },
    busy: { type: Boolean, default: false },
});

defineEmits(['toggle']);

// Stopped on purpose (systemd says inactive), as opposed to unreachable.
const turnedOff = computed(() => props.service?.installed && !props.service.active);

const memory = computed(() => props.status?.memory || { total_bytes: 0, available_bytes: 0 });
const usedPercent = computed(() => {
    const { total_bytes: total, available_bytes: available } = memory.value;
    return total ? Math.round(((total - available) / total) * 100) : 0;
});
const config = computed(() => props.status?.config || {});
const gpus = computed(() => props.status?.gpus || []);
// Tells apart "no GPU here" from "drust-llm was built without GPU support".
const gpuNote = computed(() => {
    if (gpus.value.length) return '';
    return config.value.gpu_backend ? t('aiGateway.localLlm.status.noGpu') : t('aiGateway.localLlm.status.cpuOnly');
});
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full" :class="online ? 'bg-emerald-500' : 'bg-red-500'"></span>
                <h2 class="text-sm font-semibold">drust-llm</h2>
                <span v-if="online" class="text-xs text-slate-400">v{{ status.version }} · {{ baseUrl }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded px-2 py-0.5 text-xs" :class="online ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'">
                    {{ online ? t('aiGateway.localLlm.status.running') : (turnedOff ? t('aiGateway.localLlm.status.off') : (configured ? t('aiGateway.localLlm.status.notRunning') : t('aiGateway.localLlm.status.notInstalled'))) }}
                </span>
                <button v-if="service?.installed" type="button" :disabled="busy"
                    :class="service.active
                        ? 'border border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/20'
                        : 'bg-blue-600 text-white hover:bg-blue-700'"
                    class="rounded-md px-3 py-1 text-xs font-medium disabled:opacity-50"
                    @click="$emit('toggle')">
                    {{ busy ? t('aiGateway.localLlm.status.working') : service.active ? t('aiGateway.localLlm.status.turnOff') : t('aiGateway.localLlm.status.turnOn') }}
                </button>
            </div>
        </div>

        <div v-if="!configured" class="mt-3 text-sm text-slate-600 dark:text-slate-300">
            {{ t('aiGateway.localLlm.status.notInstalledHelp') }}
            <code class="mt-2 block rounded-md bg-slate-100 px-3 py-2 text-xs dark:bg-slate-900">sudo DRUST_INSTALL_LLM=1 bash /var/www/drust/deploy/install-service.sh</code>
        </div>
        <div v-else-if="turnedOff" class="mt-3 text-sm text-slate-600 dark:text-slate-300">
            {{ t('aiGateway.localLlm.status.offHelp') }}
        </div>
        <div v-else-if="!online" class="mt-3 text-sm text-slate-600 dark:text-slate-300">
            {{ t('aiGateway.localLlm.status.unreachable') }}
            <code class="mt-2 block rounded-md bg-slate-100 px-3 py-2 text-xs dark:bg-slate-900">sudo systemctl status drust-llm &amp;&amp; sudo journalctl -u drust-llm -n 50</code>
        </div>

        <div v-else class="mt-4 grid gap-4 md:grid-cols-3">
            <div>
                <div class="text-xs text-slate-500">{{ t('aiGateway.localLlm.status.memory') }}</div>
                <div class="mt-1 h-2 overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
                    <div class="h-full rounded" :class="usedPercent > 85 ? 'bg-red-500' : 'bg-indigo-500'" :style="{ width: usedPercent + '%' }"></div>
                </div>
                <div class="mt-1 text-xs text-slate-500">{{ t('aiGateway.localLlm.status.freeOf', { free: fmtBytes(memory.available_bytes), total: fmtBytes(memory.total_bytes) }) }}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500">{{ t('aiGateway.localLlm.status.inMemory') }}</div>
                <div v-if="!status.loaded.length" class="mt-1 text-sm text-slate-400">{{ t('aiGateway.localLlm.status.noneLoaded') }}</div>
                <div v-for="m in status.loaded" :key="m.id" class="mt-1 text-sm">
                    <span class="font-medium">{{ m.id }}</span>
                    <span v-if="m.active || m.queued" class="text-xs text-amber-600"> · {{ t('aiGateway.localLlm.status.activeCount', { count: m.active }) }}<template v-if="m.queued">, {{ t('aiGateway.localLlm.status.queued', { count: m.queued }) }}</template></span>
                    <span v-else class="text-xs text-slate-400"> · {{ t('aiGateway.localLlm.status.idle', { time: fmtIdle(m.idle_seconds) }) }}</span>
                    <span class="text-xs text-slate-400"> · {{ m.gpu_layers ? 'GPU' : 'CPU' }}</span>
                </div>
                <div class="mt-3 text-xs text-slate-500">GPU</div>
                <div v-for="g in gpus" :key="g.name" class="mt-1 text-sm">
                    <i class="bi bi-gpu-card text-emerald-600"></i> {{ g.description }}
                    <span class="text-xs text-slate-400">· {{ g.integrated ? t('aiGateway.localLlm.status.integrated') : fmtBytes(g.memory_total) }} · {{ g.backend }}</span>
                </div>
                <div v-if="gpuNote" class="mt-1 text-sm text-slate-400">{{ gpuNote }}</div>
            </div>
            <div class="text-xs text-slate-500">
                <div><i18n-t keypath="aiGateway.localLlm.status.context" scope="global"><template #ctx><span class="font-medium text-slate-700 dark:text-slate-200">{{ config.ctx_size }}</span></template><template #max><span class="font-medium text-slate-700 dark:text-slate-200">{{ config.max_tokens }}</span></template></i18n-t></div>
                <div class="mt-1">{{ t('aiGateway.localLlm.status.limits', { parallel: config.parallel, threads: config.threads, loaded: config.max_loaded }) }}</div>
                <div class="mt-1">{{ t('aiGateway.localLlm.status.gpuLayers', { value: config.gpu_layers ?? t('aiGateway.localLlm.status.gpuAuto') }) }}</div>
                <div class="mt-1">{{ config.idle_unload_secs ? t('aiGateway.localLlm.status.unloadAfter', { time: fmtIdle(config.idle_unload_secs) }) : t('aiGateway.localLlm.status.unloadNever') }}</div>
                <div class="mt-1 text-slate-400"><i18n-t keypath="aiGateway.localLlm.status.changeIn" scope="global"><template #file><code>/etc/drust/llm.env</code></template></i18n-t></div>
            </div>
        </div>
    </div>
</template>

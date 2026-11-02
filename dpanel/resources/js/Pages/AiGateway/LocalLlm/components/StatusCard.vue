<script setup>
import { computed } from 'vue';
import { fmtBytes, fmtIdle } from './format';

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
                    {{ online ? 'Running' : (turnedOff ? 'Off' : (configured ? 'Not running' : 'Not installed')) }}
                </span>
                <button v-if="service?.installed" type="button" :disabled="busy"
                    :class="service.active
                        ? 'border border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/20'
                        : 'bg-blue-600 text-white hover:bg-blue-700'"
                    class="rounded-md px-3 py-1 text-xs font-medium disabled:opacity-50"
                    @click="$emit('toggle')">
                    {{ busy ? 'Working…' : service.active ? 'Turn Off' : 'Turn On' }}
                </button>
            </div>
        </div>

        <div v-if="!configured" class="mt-3 text-sm text-slate-600 dark:text-slate-300">
            The local model runner isn't installed. Install it on the server (building llama.cpp takes several minutes):
            <code class="mt-2 block rounded-md bg-slate-100 px-3 py-2 text-xs dark:bg-slate-900">sudo DRUST_INSTALL_LLM=1 bash /var/www/drust/deploy/install-service.sh</code>
        </div>
        <div v-else-if="turnedOff" class="mt-3 text-sm text-slate-600 dark:text-slate-300">
            drust-llm is off and stays off after a reboot. Its CPU and memory are free for the rest of the server, and the AI Gateway doesn't route chats to it. Turn it on to use local models again.
        </div>
        <div v-else-if="!online" class="mt-3 text-sm text-slate-600 dark:text-slate-300">
            The panel can't reach drust-llm. Check the service on the server:
            <code class="mt-2 block rounded-md bg-slate-100 px-3 py-2 text-xs dark:bg-slate-900">sudo systemctl status drust-llm &amp;&amp; sudo journalctl -u drust-llm -n 50</code>
        </div>

        <div v-else class="mt-4 grid gap-4 md:grid-cols-3">
            <div>
                <div class="text-xs text-slate-500">Server memory</div>
                <div class="mt-1 h-2 overflow-hidden rounded bg-slate-200 dark:bg-slate-700">
                    <div class="h-full rounded" :class="usedPercent > 85 ? 'bg-red-500' : 'bg-indigo-500'" :style="{ width: usedPercent + '%' }"></div>
                </div>
                <div class="mt-1 text-xs text-slate-500">{{ fmtBytes(memory.available_bytes) }} free of {{ fmtBytes(memory.total_bytes) }}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500">In memory</div>
                <div v-if="!status.loaded.length" class="mt-1 text-sm text-slate-400">No model loaded</div>
                <div v-for="m in status.loaded" :key="m.id" class="mt-1 text-sm">
                    <span class="font-medium">{{ m.id }}</span>
                    <span v-if="m.active || m.queued" class="text-xs text-amber-600"> · {{ m.active }} running<template v-if="m.queued">, {{ m.queued }} waiting</template></span>
                    <span v-else class="text-xs text-slate-400"> · idle {{ fmtIdle(m.idle_seconds) }}</span>
                </div>
            </div>
            <div class="text-xs text-slate-500">
                <div>Context <span class="font-medium text-slate-700 dark:text-slate-200">{{ config.ctx_size }}</span> tokens · max output <span class="font-medium text-slate-700 dark:text-slate-200">{{ config.max_tokens }}</span></div>
                <div class="mt-1">{{ config.parallel }} requests at once · {{ config.threads }} CPU threads · up to {{ config.max_loaded }} loaded</div>
                <div class="mt-1">Unload after {{ config.idle_unload_secs ? fmtIdle(config.idle_unload_secs) + ' idle' : 'never' }}</div>
                <div class="mt-1 text-slate-400">Change these in <code>/etc/drust/llm.env</code></div>
            </div>
        </div>
    </div>
</template>

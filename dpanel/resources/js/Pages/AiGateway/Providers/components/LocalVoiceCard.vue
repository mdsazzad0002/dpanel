<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// drust's whisper.cpp: the voice-to-text gateway, not a provider model.
const props = defineProps({
    voice: { type: Object, required: true },
    panelRoute: { type: Function, required: true },
});

const MODES = computed(() => ({
    first: t('aiGateway.models.mode.first'),
    fallback: t('aiGateway.models.mode.fallback'),
    off: t('aiGateway.models.mode.off'),
}));
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-800">
        <div class="flex items-center gap-2 font-medium">
            <span class="inline-block h-2 w-2 rounded-full" :class="voice.mode === 'off' ? 'bg-slate-400' : (voice.reachable ? 'bg-emerald-500' : 'bg-red-500')"></span>
            {{ t('aiGateway.models.localVoice') }} · {{ voice.model }}
        </div>
        <Link :href="panelRoute('ai-gateway.voice.index')" class="text-xs text-blue-600 hover:underline">{{ t('aiGateway.models.voiceSettings') }} →</Link>
        <span class="text-xs text-slate-500">
            {{ voice.mode === 'off' ? t('aiGateway.models.off') : (voice.reachable ? t('aiGateway.models.running') : t('aiGateway.models.unreachable')) }}
            · {{ MODES[voice.mode] || voice.mode }}
        </span>
        <span class="ml-auto text-xs text-slate-400">
            {{ t('aiGateway.models.uses7d', { count: voice.uses_7d }) }}<template v-if="voice.last_used_at"> · {{ t('aiGateway.models.lastUsed', { time: new Date(voice.last_used_at).toLocaleString() }) }}</template>
        </span>
    </div>
</template>

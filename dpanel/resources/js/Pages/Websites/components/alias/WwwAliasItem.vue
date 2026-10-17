<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

// www.<domain> is served by the main website's include_www flag and covered
// by its certificate, so it is listed here like an alias but acts on the
// main website.
const props = defineProps({
    website: { type: Object, required: true },
    www: { type: Object, required: true },
    // Deferred live handshake: undefined while loading, null when SSL is off.
    certificate: { type: Object, default: undefined },
});

const emit = defineEmits(['result']);
const { panelRoute, requestJson } = usePanelApi();
const busy = ref('');

const sslBadge = computed(() => {
    if (!props.www.enabled) return { label: 'Not served', tone: 'slate' };
    if (!props.www.ssl_available) return { label: 'Local domain', tone: 'slate' };
    if (!props.www.ssl_enabled) return { label: 'SSL disabled', tone: 'slate' };
    if (props.certificate === undefined) return { label: 'Checking…', tone: 'slate' };
    if (!props.certificate?.covered) return { label: 'Not covered', tone: 'red' };
    const days = props.certificate.days_remaining;
    return { label: `Valid ${days}d`, tone: days <= 30 ? 'amber' : 'emerald' };
});

const toneClasses = {
    slate: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
    red: 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-400',
    amber: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
    emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400',
};

const reload = () => router.reload({ only: ['wwwAlias', 'wwwCertificate'], preserveScroll: true });

const run = async (action, request, fallback) => {
    if (busy.value) return;
    busy.value = action;
    try {
        const data = await request();
        emit('result', data, data.type === 'warning' ? 'error' : 'success', fallback);
        // Give the gateway a moment to load a new certificate before re-checking.
        setTimeout(reload, 1500);
    } catch (error) {
        emit('result', { message: error.message }, 'error', fallback);
    } finally {
        busy.value = '';
    }
};

const toggleServed = () => run('toggle', () => requestJson(panelRoute('websites.www.update', { id: props.website.id }), {
    method: 'PATCH',
    body: { include_www: !props.www.enabled },
}), 'www alias update failed.');

const issueSsl = () => run('issue', () => requestJson(panelRoute('websites.ssl.issue', { id: props.website.id })), 'SSL issue failed.');
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-slate-700 dark:bg-slate-800/40 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <p class="truncate font-medium">{{ www.domain }}</p>
                <span class="rounded-full bg-cyan-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-400">www</span>
            </div>
            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-medium" :class="toneClasses[sslBadge.tone]">
                    <span>SSL</span><span class="opacity-40">|</span><span>{{ sslBadge.label }}</span>
                </span>
                <span v-if="www.enabled && !www.resolves" class="text-[11px] text-amber-600 dark:text-amber-400">
                    {{ www.domain }} has no DNS record yet; add one pointing here, then issue SSL.
                </span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <button type="button" :disabled="Boolean(busy)" @click="toggleServed"
                class="rounded-lg border px-3 py-1.5 text-[12px] font-medium transition disabled:opacity-60"
                :class="www.enabled
                    ? 'border-red-200 bg-red-50 text-red-600 hover:bg-red-100 dark:border-red-900 dark:bg-red-500/10 dark:text-red-400'
                    : 'border-cyan-200 bg-cyan-50 text-cyan-700 hover:bg-cyan-100 dark:border-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-400'">
                {{ busy === 'toggle' ? 'Updating...' : (www.enabled ? 'Stop serving' : 'Serve www') }}
            </button>
            <button v-if="www.enabled && www.ssl_available" type="button" :disabled="Boolean(busy)" @click="issueSsl"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-[12px] font-medium text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-60 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">
                {{ busy === 'issue' ? 'Issuing...' : 'Issue SSL' }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const { t, locale } = useI18n();
const { panelRoute } = usePanelApi();

// "Explain with AI": the server reads the same log again (newest lines,
// same filter) and the AI says what is wrong and what to do. Fixes it can
// stand behind (banning an attacking IP for this site) come with an Apply
// button that uses the panel's own IP rule endpoint, and an Undo.
const props = defineProps({
    endpoint: { type: String, required: true },
    websiteId: { type: String, required: true },
    kind: { type: String, required: true },
    count: { type: Number, default: 200 },
    filter: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const text = ref('');
const error = ref('');
const loading = ref(false);
// { type, ip, reason, state: '' | 'working' | 'done', ruleId, message }
const actions = ref([]);

const reset = () => {
    text.value = '';
    error.value = '';
    actions.value = [];
};
watch(() => props.kind, reset);

const applyAction = async (action) => {
    if (action.state === 'working') return;
    if (!confirm(t('siteLogs.ai.banConfirm', { ip: action.ip }))) return;
    action.state = 'working';
    action.message = '';
    try {
        const { data } = await window.axios.post(panelRoute('websites.ip-rules.store', { id: props.websiteId }), { rule_type: 'ban', ip_address: action.ip }, { headers: { Accept: 'application/json' } });
        action.ruleId = data?.rule?.id || '';
        action.state = 'done';
    }
    catch (e) {
        action.state = '';
        action.message = e?.response?.data?.message || e?.message || t('siteLogs.ai.applyFailed');
    }
};

const undoAction = async (action) => {
    if (!action.ruleId || action.state === 'working') return;
    action.state = 'working';
    try {
        await window.axios.delete(panelRoute('websites.ip-rules.destroy', { id: props.websiteId, rule: action.ruleId }), { headers: { Accept: 'application/json' } });
        action.ruleId = '';
        action.state = '';
    }
    catch (e) {
        action.state = 'done';
        action.message = e?.response?.data?.message || e?.message || t('siteLogs.ai.applyFailed');
    }
};

// "- " lines become bullets, "What to do:" starts the steps.
const lines = computed(() => text.value.split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
    if (/^[-•*]\s+/.test(line)) return { kind: 'bullet', text: line.replace(/^[-•*]\s+/, '') };
    if (/^(what to do|করণীয়)\s*:?/i.test(line)) return { kind: 'heading', text: line };

    return { kind: 'text', text: line.replace(/^\d+\.\s+/, '') };
}));

const run = async () => {
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(props.endpoint, {
            kind: props.kind,
            // The AI reads at most the newest 500 lines.
            lines: Math.min(props.count, 500),
            filter: props.filter,
            language: String(locale.value).startsWith('bn') ? 'bn' : 'en',
        }, { headers: { Accept: 'application/json' } });
        text.value = data.text || '';
        actions.value = (data.actions || []).map((action) => ({ ...action, state: '', ruleId: '', message: '' }));
    }
    catch (e) {
        error.value = e?.response?.data?.message || e?.message || t('siteLogs.ai.failed');
    }
    finally {
        loading.value = false;
    }
};
</script>

<template>
    <div>
        <button
            v-if="!text && !error"
            type="button"
            :disabled="loading || disabled"
            class="inline-flex items-center gap-2 rounded-xl border border-violet-300 px-3 py-2 text-sm font-semibold text-violet-700 hover:bg-violet-50 disabled:opacity-50 dark:border-violet-500/40 dark:text-violet-200 dark:hover:bg-violet-500/15"
            @click="run"
        >
            <i :class="['bi', loading ? 'bi-arrow-repeat animate-spin' : 'bi-stars']"></i>
            {{ loading ? t('siteLogs.ai.working') : t('siteLogs.ai.explain') }}
        </button>

        <section v-else class="rounded-2xl border border-violet-200 bg-violet-50/70 p-4 text-sm text-slate-800 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-slate-200" aria-live="polite">
            <div class="flex items-center gap-2">
                <i class="bi bi-stars text-violet-500"></i>
                <span class="font-semibold">{{ t('siteLogs.ai.title') }}</span>
                <button type="button" :disabled="loading" class="ml-auto flex h-7 w-7 items-center justify-center rounded-full text-slate-500 hover:bg-violet-100 dark:hover:bg-slate-800" :title="t('siteLogs.ai.again')" @click="run">
                    <i :class="['bi', loading ? 'bi-arrow-repeat animate-spin' : 'bi-arrow-clockwise']"></i>
                </button>
                <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full text-slate-500 hover:bg-violet-100 dark:hover:bg-slate-800" :title="t('siteLogs.ai.close')" @click="reset">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <p v-if="error" class="mt-2 text-red-600 dark:text-red-400">{{ error }}</p>
            <div v-else class="mt-2 space-y-1.5">
                <template v-for="(line, i) in lines" :key="i">
                    <p v-if="line.kind === 'bullet'" class="flex gap-2 pl-1"><span class="text-violet-500">•</span><span class="break-words">{{ line.text }}</span></p>
                    <p v-else-if="line.kind === 'heading'" class="pt-2 font-semibold">{{ line.text }}</p>
                    <p v-else class="break-words">{{ line.text }}</p>
                </template>

                <div v-if="actions.length" class="mt-3 rounded-xl border border-violet-200 bg-white/70 p-3 dark:border-violet-500/30 dark:bg-slate-900/50">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('siteLogs.ai.fixes') }}</p>
                    <ul class="space-y-2">
                        <li v-for="action in actions" :key="action.ip" class="flex flex-wrap items-center gap-2">
                            <i class="bi bi-shield-x text-rose-500"></i>
                            <span class="font-medium">{{ t('siteLogs.ai.banIp', { ip: action.ip }) }}</span>
                            <span v-if="action.reason" class="min-w-0 flex-1 break-words text-xs text-slate-500 dark:text-slate-400">{{ action.reason }}</span>
                            <span v-else class="flex-1"></span>
                            <template v-if="action.state === 'done'">
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600"><i class="bi bi-check2-circle"></i>{{ t('siteLogs.ai.applied') }}</span>
                                <button v-if="action.ruleId" type="button" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="undoAction(action)">{{ t('siteLogs.ai.undo') }}</button>
                            </template>
                            <button
                                v-else
                                type="button"
                                :disabled="action.state === 'working'"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-3 py-1 text-xs font-semibold text-white hover:bg-rose-700 disabled:opacity-60"
                                @click="applyAction(action)"
                            >
                                <i :class="['bi', action.state === 'working' ? 'bi-arrow-repeat animate-spin' : 'bi-check2']"></i>{{ t('siteLogs.ai.apply') }}
                            </button>
                            <p v-if="action.message" class="w-full text-xs text-red-600">{{ action.message }}</p>
                        </li>
                    </ul>
                </div>
                <p class="pt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ t('siteLogs.ai.disclaimer') }}</p>
            </div>
        </section>
    </div>
</template>

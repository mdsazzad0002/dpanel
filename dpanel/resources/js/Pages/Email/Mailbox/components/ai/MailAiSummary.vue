<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// "Summarize this email": the AI Gateway reads the open message and its
// conversation and returns a short summary with the action asked of the reader.
const props = defineProps({
    endpoint: { type: String, required: true },
    folder: { type: String, required: true },
    uid: { type: [Number, String], required: true },
    isDark: { type: Boolean, default: false },
});

const summary = ref('');
const error = ref('');
const loading = ref(false);
const open = ref(false);

const ui = computed(() => (props.isDark
    ? { card: 'border-violet-500/30 bg-violet-500/10 text-slate-200', muted: 'text-slate-400', button: 'border-violet-500/40 text-violet-200 hover:bg-violet-500/15', icon: 'text-slate-400 hover:bg-slate-800 hover:text-slate-100' }
    : { card: 'border-violet-200 bg-violet-50 text-slate-800', muted: 'text-slate-500', button: 'border-violet-300 text-violet-700 hover:bg-violet-50', icon: 'text-slate-500 hover:bg-violet-100 hover:text-slate-900' }));

// "- " lines become bullets; the "Action:" line is shown apart.
const lines = computed(() => summary.value.split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
    if (/^[-•*]\s+/.test(line)) return { kind: 'bullet', text: line.replace(/^[-•*]\s+/, '') };
    if (/^action\s*:/i.test(line)) return { kind: 'action', text: line.replace(/^action\s*:\s*/i, '') };

    return { kind: 'text', text: line };
}));

watch(() => `${props.folder}:${props.uid}`, () => {
    summary.value = '';
    error.value = '';
    open.value = false;
});

const run = async () => {
    open.value = true;
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(props.endpoint, { folder: props.folder, uid: Number(props.uid) }, { headers: { Accept: 'application/json' } });
        summary.value = data?.text || '';
    }
    catch (e) {
        error.value = e?.response?.data?.message || e?.message || t('email.mailbox.ai.failed');
    }
    finally {
        loading.value = false;
    }
};
</script>

<template>
    <div>
        <button
            v-if="!open"
            type="button"
            :class="[ui.button, 'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-semibold transition']"
            @click="run"
        >
            <i class="bi bi-stars"></i>{{ t('email.mailbox.ai.summarize') }}
        </button>

        <section v-else :class="[ui.card, 'rounded-xl border px-4 py-3 text-sm']" :aria-label="t('email.mailbox.ai.summaryTitle')" aria-live="polite">
            <div class="flex items-center gap-2">
                <i class="bi bi-stars text-violet-500"></i>
                <span class="font-semibold">{{ t('email.mailbox.ai.summaryTitle') }}</span>
                <button v-if="!loading" type="button" :class="[ui.icon, 'ml-auto flex h-7 w-7 items-center justify-center rounded-full']" :title="t('email.mailbox.ai.regenerate')" @click="run">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button type="button" :class="[ui.icon, loading ? 'ml-auto' : '', 'flex h-7 w-7 items-center justify-center rounded-full']" :title="t('email.mailbox.ai.close')" @click="open = false">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <p v-if="loading" :class="[ui.muted, 'mt-2 flex items-center gap-2']">
                <i class="bi bi-arrow-repeat animate-spin"></i>{{ t('email.mailbox.ai.summarizing') }}
            </p>
            <p v-else-if="error" class="mt-2 text-rose-600 dark:text-rose-400">{{ error }}</p>
            <div v-else class="mt-2 space-y-1.5">
                <template v-for="(line, i) in lines" :key="i">
                    <p v-if="line.kind === 'text'">{{ line.text }}</p>
                    <p v-else-if="line.kind === 'bullet'" class="flex gap-2 pl-1"><span class="text-violet-500">•</span><span>{{ line.text }}</span></p>
                    <p v-else class="mt-2 font-medium"><i class="bi bi-check2-square mr-1 text-violet-500"></i>{{ t('email.mailbox.ai.action') }}: {{ line.text }}</p>
                </template>
                <p :class="[ui.muted, 'pt-1 text-[11px]']">{{ t('email.mailbox.ai.disclaimer') }}</p>
            </div>
        </section>
    </div>
</template>

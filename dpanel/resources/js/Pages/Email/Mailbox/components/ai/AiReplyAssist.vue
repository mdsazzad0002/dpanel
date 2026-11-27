<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// "Help me write" inside the reply box: the AI drafts the reply body from the
// conversation, a tone and optional instructions. The draft only fills the
// editor; the user edits and sends it.
const props = defineProps({
    endpoint: { type: String, required: true },
    folder: { type: String, required: true },
    uid: { type: [Number, String], required: true },
    hasDraft: { type: Boolean, default: false },
    // Opened from the "AI reply" button: draft right away.
    autoRun: { type: Boolean, default: false },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['draft', 'notify']);

const open = ref(false);
const tone = ref('friendly');
const instructions = ref('');
const loading = ref(false);

const tones = computed(() => [
    { id: 'friendly', label: t('email.mailbox.ai.tone.friendly') },
    { id: 'formal', label: t('email.mailbox.ai.tone.formal') },
    { id: 'short', label: t('email.mailbox.ai.tone.short') },
]);

const ui = computed(() => (props.isDark
    ? { panel: 'border-violet-500/30 bg-violet-500/10', input: 'border-slate-700 bg-slate-900 text-slate-100 placeholder:text-slate-500', chip: 'border-slate-700 text-slate-300 hover:bg-slate-800', chipOn: 'border-violet-400 bg-violet-500/20 text-violet-100', button: 'text-violet-300 hover:bg-violet-500/15' }
    : { panel: 'border-violet-200 bg-violet-50/70', input: 'border-slate-300 bg-white text-slate-900 placeholder:text-slate-400', chip: 'border-slate-300 text-slate-600 hover:bg-white', chipOn: 'border-violet-400 bg-violet-100 text-violet-800', button: 'text-violet-700 hover:bg-violet-100' }));

const generate = async () => {
    if (loading.value) return;
    if (props.hasDraft && !confirm(t('email.mailbox.ai.replaceDraft'))) return;
    loading.value = true;
    try {
        const { data } = await window.axios.post(props.endpoint, {
            folder: props.folder,
            uid: Number(props.uid),
            tone: tone.value,
            instructions: instructions.value,
        }, { headers: { Accept: 'application/json' } });
        if (data?.text) {
            emit('draft', data.text);
            open.value = false;
        }
    }
    catch (e) {
        emit('notify', e?.response?.data?.message || e?.message || t('email.mailbox.ai.failed'), 'error');
    }
    finally {
        loading.value = false;
    }
};

onMounted(() => {
    if (props.autoRun) generate();
});
</script>

<template>
    <div class="px-4 pt-2">
        <button
            v-if="!open"
            type="button"
            :class="[ui.button, 'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-70']"
            :disabled="loading"
            @click="open = true"
        >
            <i :class="['bi', loading ? 'bi-arrow-repeat animate-spin' : 'bi-stars']"></i>
            {{ loading ? t('email.mailbox.ai.writing') : t('email.mailbox.ai.helpMeWrite') }}
        </button>

        <div v-else :class="[ui.panel, 'space-y-2 rounded-xl border p-3']" @keydown.esc.stop="open = false">
            <div class="flex flex-wrap items-center gap-1.5" role="radiogroup" :aria-label="t('email.mailbox.ai.toneLabel')">
                <button
                    v-for="item in tones"
                    :key="item.id"
                    type="button"
                    role="radio"
                    :aria-checked="tone === item.id"
                    :class="[tone === item.id ? ui.chipOn : ui.chip, 'h-7 rounded-full border px-3 text-xs font-medium transition']"
                    @click="tone = item.id"
                >
                    {{ item.label }}
                </button>
            </div>
            <textarea
                v-model="instructions"
                rows="2"
                maxlength="1000"
                :class="[ui.input, 'w-full resize-y rounded-lg border px-3 py-2 text-sm outline-none focus:border-violet-400 focus:ring-0']"
                :placeholder="t('email.mailbox.ai.instructionsPlaceholder')"
                @keydown.ctrl.enter.prevent="generate"
                @keydown.meta.enter.prevent="generate"
            ></textarea>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full bg-violet-600 px-4 text-xs font-semibold text-white transition hover:bg-violet-700 disabled:cursor-wait disabled:opacity-70"
                    :disabled="loading"
                    @click="generate"
                >
                    <i :class="['bi', loading ? 'bi-arrow-repeat animate-spin' : 'bi-stars']"></i>
                    {{ loading ? t('email.mailbox.ai.writing') : t('email.mailbox.ai.generate') }}
                </button>
                <button type="button" :class="[ui.button, 'h-8 rounded-full px-3 text-xs font-medium']" @click="open = false">{{ t('email.mailbox.ai.cancel') }}</button>
                <span class="ml-auto hidden text-[11px] opacity-60 sm:inline">{{ t('email.mailbox.ai.reviewNote') }}</span>
            </div>
        </div>
    </div>
</template>

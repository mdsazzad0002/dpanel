<script setup>
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// "Help me write" in the compose window: a short description becomes a
// subject and body. It only fills the fields; nothing is sent.
const props = defineProps({
    endpoint: { type: String, required: true },
    to: { type: String, default: '' },
    hasDraft: { type: Boolean, default: false },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['draft', 'notify']);

const open = ref(false);
const description = ref('');
const loading = ref(false);
const input = ref(null);

const ui = computed(() => (props.isDark
    ? { panel: 'border-violet-500/30 bg-violet-500/10', input: 'border-slate-700 bg-slate-900 text-slate-100 placeholder:text-slate-500', button: 'text-violet-300 hover:bg-violet-500/15' }
    : { panel: 'border-violet-200 bg-violet-50/70', input: 'border-slate-300 bg-white text-slate-900 placeholder:text-slate-400', button: 'text-violet-700 hover:bg-violet-100' }));

const show = async () => {
    open.value = true;
    await nextTick();
    input.value?.focus();
};

const generate = async () => {
    if (loading.value) return;
    if (description.value.trim() === '') {
        input.value?.focus();
        return;
    }
    if (props.hasDraft && !confirm(t('email.mailbox.ai.replaceDraft'))) return;
    loading.value = true;
    try {
        const { data } = await window.axios.post(props.endpoint, { description: description.value, to: props.to }, { headers: { Accept: 'application/json' } });
        if (data?.text) {
            emit('draft', { subject: data.subject || '', text: data.text });
            open.value = false;
            description.value = '';
        }
    }
    catch (e) {
        emit('notify', e?.response?.data?.message || e?.message || t('email.mailbox.ai.failed'));
    }
    finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="px-4 pt-2">
        <button v-if="!open" type="button" :class="[ui.button, 'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-semibold transition']" @click="show">
            <i class="bi bi-stars"></i>{{ t('email.mailbox.ai.helpMeWrite') }}
        </button>

        <div v-else :class="[ui.panel, 'space-y-2 rounded-xl border p-3']" @keydown.esc.stop="open = false">
            <textarea
                ref="input"
                v-model="description"
                rows="2"
                maxlength="2000"
                :class="[ui.input, 'w-full resize-y rounded-lg border px-3 py-2 text-sm outline-none focus:border-violet-400 focus:ring-0']"
                :placeholder="t('email.mailbox.ai.composePlaceholder')"
                @keydown.ctrl.enter.prevent.stop="generate"
                @keydown.meta.enter.prevent.stop="generate"
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

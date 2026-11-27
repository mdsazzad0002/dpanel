<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Footer button of the compose window and the reply box: one click fixes
// grammar, shortens, changes the tone or translates what has been written.
const props = defineProps({
    endpoint: { type: String, required: true },
    text: { type: String, default: '' },
    isDark: { type: Boolean, default: false },
});

const emit = defineEmits(['result', 'notify']);

const open = ref(false);
const loading = ref('');
const root = ref(null);

const actions = computed(() => [
    { id: 'improve', icon: 'bi-magic', label: t('email.mailbox.ai.polish.improve') },
    { id: 'shorter', icon: 'bi-arrows-collapse', label: t('email.mailbox.ai.polish.shorter') },
    { id: 'formal', icon: 'bi-briefcase', label: t('email.mailbox.ai.polish.formal') },
    { id: 'friendly', icon: 'bi-emoji-smile', label: t('email.mailbox.ai.polish.friendly') },
    { id: 'translate_en', icon: 'bi-translate', label: t('email.mailbox.ai.polish.translateEn') },
    { id: 'translate_bn', icon: 'bi-translate', label: t('email.mailbox.ai.polish.translateBn') },
]);

const ui = computed(() => (props.isDark
    ? { button: 'text-violet-300 hover:bg-violet-500/15', menu: 'border-slate-700 bg-slate-900 text-slate-200 shadow-black/50', item: 'hover:bg-slate-800' }
    : { button: 'text-violet-700 hover:bg-violet-50', menu: 'border-slate-200 bg-white text-slate-800 shadow-slate-900/15', item: 'hover:bg-slate-100' }));

const toggle = () => {
    if (props.text.trim() === '') {
        emit('notify', t('email.mailbox.ai.polish.empty'));
        return;
    }
    open.value = !open.value;
};

const run = async (action) => {
    open.value = false;
    loading.value = action;
    try {
        const { data } = await window.axios.post(props.endpoint, { text: props.text, action }, { headers: { Accept: 'application/json' } });
        if (data?.text) emit('result', data.text);
    }
    catch (e) {
        emit('notify', e?.response?.data?.message || e?.message || t('email.mailbox.ai.failed'));
    }
    finally {
        loading.value = '';
    }
};

const onOutside = (event) => {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false;
};
onMounted(() => document.addEventListener('mousedown', onOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));
</script>

<template>
    <div ref="root" class="relative" @keydown.esc.stop="open = false">
        <button
            type="button"
            :class="[ui.button, 'flex h-9 items-center gap-1.5 rounded-full px-2.5 text-sm font-semibold transition disabled:cursor-wait disabled:opacity-70']"
            :title="t('email.mailbox.ai.polish.title')"
            aria-haspopup="menu"
            :aria-expanded="open"
            :disabled="!!loading"
            @click="toggle"
        >
            <i :class="['bi', loading ? 'bi-arrow-repeat animate-spin' : 'bi-stars']"></i>
            <span class="hidden md:inline">{{ loading ? t('email.mailbox.ai.writing') : t('email.mailbox.ai.polish.title') }}</span>
        </button>
        <div v-if="open" :class="[ui.menu, 'absolute bottom-11 left-0 z-40 w-56 rounded-xl border py-1 shadow-xl']" role="menu">
            <button v-for="item in actions" :key="item.id" type="button" role="menuitem" :class="[ui.item, 'flex w-full items-center gap-3 px-3 py-2 text-left text-sm']" @click="run(item.id)">
                <i :class="['bi', item.icon, 'text-violet-500']"></i>{{ item.label }}
            </button>
        </div>
    </div>
</template>

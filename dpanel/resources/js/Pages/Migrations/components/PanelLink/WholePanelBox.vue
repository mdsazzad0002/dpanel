<script setup>
// The whole-panel clone over a live connection: the full backup moves without
// a manual download/upload, then is restored from the import list (pull) or
// on the other panel (push), like an uploaded backup.
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

defineProps({
    direction: { type: String, required: true },
    name: { type: String, default: '' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['start']);
const { t } = useI18n();

const open = ref(false);
const confirmation = ref('');

const start = () => {
    emit('start', { confirmation: confirmation.value });
    confirmation.value = '';
    open.value = false;
};
</script>

<template>
    <div class="rounded-xl border border-amber-300 bg-amber-50/60 p-4 dark:border-amber-800 dark:bg-amber-500/5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-amber-900 dark:text-amber-200"><i class="bi bi-hdd-stack"></i> {{ direction === 'pull' ? t('panelLink.wholeFrom', { name }) : t('panelLink.wholeTo', { name }) }}</p>
                <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ direction === 'pull' ? t('panelLink.wholePullHelp') : t('panelLink.wholePushHelp') }}</p>
            </div>
            <button v-if="!open" type="button" :disabled="busy" class="rounded-lg border border-amber-400 px-3 py-1.5 text-sm font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-50 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-500/10" @click="open = true">
                {{ t('panelLink.wholeStart') }}
            </button>
        </div>
        <form v-if="open" class="mt-3 flex flex-col gap-2 sm:flex-row" @submit.prevent="start">
            <input v-model.trim="confirmation" required pattern="CLONE" :placeholder="t('panelLink.typeClone')" class="min-w-0 flex-1 rounded-lg border-amber-300 font-mono text-sm dark:border-amber-800 dark:bg-slate-800" />
            <button :disabled="busy || confirmation !== 'CLONE'" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-50">{{ t('panelLink.wholeConfirm') }}</button>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="open = false">{{ t('common.cancel') }}</button>
        </form>
    </div>
</template>

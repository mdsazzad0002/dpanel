<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { when } from './runLabels';

// Confirms a rollback and whether the database goes back too (backlog U6).
const props = defineProps({
    run: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'cancel']);
const { t } = useI18n();
const restoreDatabase = ref(true);
const confirm = () => emit('confirm', { run_id: props.run.run_id, restore_database: restoreDatabase.value });
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="emit('cancel')">
        <div class="w-full max-w-lg space-y-3 rounded-2xl bg-white p-5 shadow-xl dark:bg-slate-900">
            <h2 class="text-base font-semibold">{{ t('panelUpdate.rollbackTitle', { version: run.from_version || run.from_ref || '?' }) }}</h2>
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('panelUpdate.rollbackHelp') }}</p>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="restoreDatabase" type="checkbox" class="mt-1 rounded" />
                <span>
                    {{ t('panelUpdate.restoreDatabase') }}
                    <span class="block text-xs text-slate-500">{{ t('panelUpdate.restoreDatabaseHelp', { time: when(run.started_at) }) }}</span>
                </span>
            </label>
            <p v-if="!restoreDatabase" class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-950 dark:text-amber-300">{{ t('panelUpdate.keepDatabaseWarning') }}</p>
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600" @click="emit('cancel')">{{ t('common.cancel') }}</button>
                <button type="button" :disabled="busy" class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-50" @click="confirm">{{ t('panelUpdate.rollBack') }}</button>
            </div>
        </div>
    </div>
</template>

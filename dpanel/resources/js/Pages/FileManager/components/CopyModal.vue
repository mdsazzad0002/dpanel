<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    fm: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="fm.modalType === 'copy'" class="space-y-3">
        <p class="text-xs text-slate-500">{{ t('fileManager.modal.selectedItems', { count: fm.selectedCount || (fm.singleSelectedItem ? 1 : 0) }) }}</p>
        <input v-model="fm.copyForm.destination_path" type="text" :placeholder="t('fileManager.modal.destinationPlaceholder')" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        <div v-if="(fm.copyForm.item_paths || []).length === 1">
            <label class="mb-1 block text-xs font-medium text-slate-500">{{ t('fileManager.copy.name') }}</label>
            <input v-model="fm.copyForm.new_name" type="text" :placeholder="t('fileManager.modal.newNamePlaceholder')" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
        </div>
        <p class="text-xs text-slate-500">{{ t('fileManager.copy.note') }}</p>
        <button type="button" :disabled="fm.copyForm.processing || (!fm.selectedCount && !fm.singleSelectedItem)" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitCopy">
            {{ fm.copyForm.processing ? t('fileManager.copy.copying') : t('fileManager.copy.submit') }}
        </button>
    </div>
</template>

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
    <div v-if="fm.modalType === 'unzip'" class="space-y-3">
        <p class="text-xs text-slate-500 break-all">{{ t('fileManager.unzip.archive', { path: fm.unzipForm.zip_path }) }}</p>
        <label class="block text-xs text-slate-500" for="unzip-destination">{{ t('fileManager.unzip.extractTo') }}</label>
        <input id="unzip-destination" v-model="fm.unzipForm.destination_path" type="text" :placeholder="t('fileManager.modal.destinationPlaceholder')" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" @keydown.enter.prevent="fm.submitUnzip" />
        <p class="text-xs text-slate-500">{{ t('fileManager.unzip.note') }}</p>
        <p class="text-xs text-slate-500">{{ t('fileManager.unzip.formats') }}</p>
        <button type="button" :disabled="fm.unzipInProgress || !fm.unzipForm.zip_path" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-60" @click="fm.submitUnzip">
            {{ fm.unzipInProgress ? t('fileManager.unzip.extracting') : t('fileManager.unzip.submit') }}
        </button>
    </div>
</template>

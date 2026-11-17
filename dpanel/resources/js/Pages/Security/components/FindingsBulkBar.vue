<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    count: { type: Number, required: true },
    busy: { type: Boolean, default: false },
});

defineEmits(['delete', 'clear']);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm dark:border-blue-900 dark:bg-blue-950/40">
        <span class="mr-auto">{{ count === 1 ? t('security.findingsBulkBar.selectedOne') : t('security.findingsBulkBar.selectedMany', { count }) }}</span>
        <button type="button" :disabled="busy" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-60" @click="$emit('delete')">
            {{ busy ? t('security.findingsBulkBar.deleting') : t('security.findingsBulkBar.deleteN', { count }) }}
        </button>
        <button type="button" :disabled="busy" class="rounded-md px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 disabled:opacity-60 dark:text-slate-300 dark:hover:bg-slate-800" @click="$emit('clear')">
            {{ t('security.findingsBulkBar.clear') }}
        </button>
    </div>
</template>

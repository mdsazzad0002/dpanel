<script setup>
// Confirm deleting a database from the panel. Only with the box ticked is it
// also dropped from its server (MariaDB, PostgreSQL or MongoDB), with its user.
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    database: { type: Object, default: null },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'confirm']);
const { t } = useI18n();

const dropFromServer = ref(false);
watch(() => props.show, (open) => { if (open) dropFromServer.value = false; });
</script>

<template>
    <Modal :show="show" max-width="md" :closeable="!busy" @close="emit('close')">
        <form v-if="database" class="p-5" @submit.prevent="emit('confirm', dropFromServer)">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400">
                    <i class="bi bi-database-x"></i>
                </span>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('databases.delete.title', { name: database.database_name }) }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('databases.delete.help') }}</p>
                </div>
            </div>

            <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border p-3" :class="dropFromServer ? 'border-red-400 bg-red-50/60 dark:border-red-700 dark:bg-red-500/10' : 'border-slate-200 dark:border-slate-700'">
                <input v-model="dropFromServer" type="checkbox" class="mt-0.5 rounded border-slate-300 text-red-600" />
                <span>
                    <span class="block text-sm font-semibold text-slate-900 dark:text-slate-100">{{ t('databases.delete.dropTitle') }}</span>
                    <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ t('databases.delete.dropHelp') }}</span>
                </span>
            </label>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" :disabled="busy" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800" @click="emit('close')">
                    {{ t('common.cancel') }}
                </button>
                <button type="submit" :disabled="busy" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50">
                    <i v-if="busy" class="bi bi-arrow-repeat animate-spin"></i>
                    {{ dropFromServer ? t('databases.delete.dropButton') : t('databases.delete.button') }}
                </button>
            </div>
        </form>
    </Modal>
</template>

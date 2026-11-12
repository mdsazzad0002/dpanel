<script setup>
// Small confirm dialog for deleting a website: "Move to trash" keeps an archive
// in Website Trash, "Delete permanently" keeps nothing (typed domain confirms).
// An alias has no files of its own, so it only gets a plain "Remove".
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    website: { type: Object, default: null },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'confirm']);
const { t } = useI18n();

const mode = ref('trash');
const typed = ref('');
// Unticked by default: the databases stay on the server unless asked.
const dropDatabases = ref(false);

const isAlias = computed(() => ['alias', 'alis'].includes(String(props.website?.type || '').toLowerCase()));
const canConfirm = computed(() => !props.busy && (isAlias.value || mode.value === 'trash' || typed.value.trim().toLowerCase() === String(props.website?.domain || '').toLowerCase()));

watch(() => props.show, (open) => {
    if (open) {
        mode.value = 'trash';
        typed.value = '';
        dropDatabases.value = false;
    }
});

const confirm = () => {
    if (canConfirm.value) emit('confirm', isAlias.value ? 'trash' : mode.value, !isAlias.value && dropDatabases.value);
};
</script>

<template>
    <Modal :show="show" max-width="md" :closeable="!busy" @close="emit('close')">
        <form v-if="website" class="p-5" @submit.prevent="confirm">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400">
                    <i class="bi bi-trash3"></i>
                </span>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('websites.delete.confirm', { domain: website.domain }) }}</h3>
                    <p v-if="isAlias" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('websites.delete.aliasHelp', { domain: website.domain }) }}</p>
                </div>
            </div>

            <div v-if="!isAlias" class="mt-4 space-y-2">
                <label
                    v-for="option in ['trash', 'permanent']"
                    :key="option"
                    class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
                    :class="mode === option
                        ? (option === 'trash' ? 'border-indigo-400 bg-indigo-50/60 dark:border-indigo-600 dark:bg-indigo-500/10' : 'border-red-400 bg-red-50/60 dark:border-red-700 dark:bg-red-500/10')
                        : 'border-slate-200 hover:border-slate-300 dark:border-slate-700'"
                >
                    <input v-model="mode" type="radio" :value="option" class="mt-0.5" :class="option === 'trash' ? 'text-indigo-600' : 'text-red-600'" />
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-slate-900 dark:text-slate-100">
                            <i :class="option === 'trash' ? 'bi bi-archive' : 'bi bi-x-octagon'" class="mr-1"></i>{{ t(`websites.delete.${option}Title`) }}
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ t(`websites.delete.${option}Help`) }}</span>
                    </span>
                </label>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3" :class="dropDatabases ? 'border-red-400 bg-red-50/60 dark:border-red-700 dark:bg-red-500/10' : 'border-slate-200 dark:border-slate-700'">
                    <input v-model="dropDatabases" type="checkbox" class="mt-0.5 rounded border-slate-300 text-red-600" />
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-slate-900 dark:text-slate-100"><i class="bi bi-database-x mr-1"></i>{{ t('websites.delete.dropDatabases') }}</span>
                        <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ mode === 'trash' ? t('websites.delete.dropDatabasesTrash') : t('websites.delete.dropDatabasesPermanent') }}</span>
                    </span>
                </label>

                <label v-if="mode === 'permanent'" class="block pt-1 text-xs text-slate-600 dark:text-slate-300">
                    {{ t('websites.delete.typeDomain', { domain: website.domain }) }}
                    <input v-model="typed" autocomplete="off" :placeholder="website.domain" class="mt-1 block w-full rounded-lg border-red-300 text-sm focus:border-red-500 focus:ring-red-500 dark:border-red-800 dark:bg-slate-800" />
                </label>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" :disabled="busy" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800" @click="emit('close')">
                    {{ t('common.cancel') }}
                </button>
                <button
                    type="submit"
                    :disabled="!canConfirm"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    :class="!isAlias && mode === 'trash' ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-red-600 hover:bg-red-700'"
                >
                    <i v-if="busy" class="bi bi-arrow-repeat animate-spin"></i>
                    {{ busy ? t('websites.delete.deleting') : isAlias ? t('websites.delete.removeAlias') : t(`websites.delete.${mode}Button`) }}
                </button>
            </div>
        </form>
    </Modal>
</template>

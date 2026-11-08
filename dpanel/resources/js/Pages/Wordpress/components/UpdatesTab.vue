<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useWordpressApi } from '../useWordpressApi';
import ItemTable from './ItemTable.vue';

const props = defineProps({ site: { type: Object, required: true } });
const emit = defineEmits(['version']);
const { t } = useI18n();
const api = useWordpressApi();

const info = ref(null);
const loading = ref(false);
const busy = ref('');
const error = ref('');
const notice = ref('');

const load = async () => {
    loading.value = true;
    try {
        info.value = await api.get('wordpress.info', { site: props.site.id });
        emit('version', info.value.version);
        error.value = '';
    } catch (e) {
        error.value = api.message(e, t('wordpress.loadFailed'));
    } finally {
        loading.value = false;
    }
};

const update = async (kind, names = []) => {
    busy.value = kind;
    error.value = '';
    notice.value = '';
    try {
        info.value = await api.post('wordpress.update', { site: props.site.id }, { kind, names });
        emit('version', info.value.version);
        notice.value = t('wordpress.updated');
    } catch (e) {
        error.value = api.message(e, t('wordpress.updateFailed'));
    } finally {
        busy.value = '';
    }
};

onMounted(load);
</script>

<template>
    <div class="space-y-4 p-4">
        <p v-if="loading && !info" class="text-sm text-slate-500">{{ t('common.loading') }}</p>
        <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
        <div v-if="notice" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ notice }}</div>

        <template v-if="info">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <div>
                    <p class="text-xs uppercase tracking-wider text-slate-400">{{ t('wordpress.core') }}</p>
                    <p class="text-lg font-bold text-slate-800 dark:text-slate-100">WordPress {{ info.version }}</p>
                    <p class="text-sm" :class="info.core_update ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">
                        {{ info.core_update ? t('wordpress.coreUpdate', { version: info.core_update }) : t('wordpress.upToDate') }}
                    </p>
                </div>
                <button v-if="info.core_update" type="button" :disabled="!!busy" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="update('core')">
                    <i v-if="busy === 'core'" class="bi bi-arrow-repeat mr-1 animate-spin"></i>{{ t('wordpress.updateCore') }}
                </button>
            </div>

            <ItemTable :title="t('wordpress.plugins')" :items="info.plugins" :busy="busy === 'plugins'" :disabled="!!busy" @update="update('plugins', $event)" />
            <ItemTable :title="t('wordpress.themes')" :items="info.themes" :busy="busy === 'themes'" :disabled="!!busy" @update="update('themes', $event)" />
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ t('wordpress.updateHelp') }}</p>
        </template>
    </div>
</template>

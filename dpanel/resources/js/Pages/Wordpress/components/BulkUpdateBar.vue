<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useWordpressApi } from '../useWordpressApi';

const props = defineProps({ sites: { type: Array, required: true } });
const emit = defineEmits(['done', 'clear']);
const { t } = useI18n();
const api = useWordpressApi();

const running = ref(false);
const progress = ref([]);

// One site at a time: core, then plugins, then themes, so a failure is easy to place.
const run = async (kinds) => {
    if (!window.confirm(t('wordpress.bulkConfirm', { count: props.sites.length }))) return;
    running.value = true;
    progress.value = props.sites.map((site) => ({ id: site.id, domain: site.domain, state: 'waiting', message: '' }));
    for (const row of progress.value) {
        row.state = 'running';
        try {
            for (const kind of kinds) await api.post('wordpress.update', { site: row.id }, { kind });
            row.state = 'done';
        } catch (e) {
            row.state = 'failed';
            row.message = api.message(e, t('wordpress.updateFailed'));
        }
    }
    running.value = false;
    emit('done');
};
</script>

<template>
    <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
        <div class="flex flex-wrap items-center gap-2">
            <p class="mr-auto text-sm font-semibold text-blue-900 dark:text-blue-100">{{ t('wordpress.selected', { count: sites.length }) }}</p>
            <button type="button" :disabled="running" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50" @click="run(['core', 'plugins', 'themes'])">{{ t('wordpress.updateEverything') }}</button>
            <button type="button" :disabled="running" class="rounded-lg border border-blue-300 px-3 py-1.5 text-sm font-semibold text-blue-800 disabled:opacity-50 dark:border-blue-800 dark:text-blue-200" @click="run(['plugins'])">{{ t('wordpress.updatePlugins') }}</button>
            <button type="button" :disabled="running" class="rounded-lg px-3 py-1.5 text-sm text-blue-800 disabled:opacity-50 dark:text-blue-200" @click="emit('clear')">{{ t('common.cancel') }}</button>
        </div>
        <ul v-if="progress.length" class="mt-3 space-y-1 text-sm">
            <li v-for="row in progress" :key="row.id" class="flex items-center gap-2">
                <i class="bi" :class="{ 'bi-hourglass text-slate-400': row.state === 'waiting', 'bi-arrow-repeat animate-spin text-blue-600': row.state === 'running', 'bi-check-circle-fill text-emerald-600': row.state === 'done', 'bi-x-circle-fill text-red-600': row.state === 'failed' }"></i>
                <span class="font-medium">{{ row.domain }}</span>
                <span v-if="row.message" class="truncate text-red-700 dark:text-red-300">{{ row.message }}</span>
            </li>
        </ul>
    </div>
</template>

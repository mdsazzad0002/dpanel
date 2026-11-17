<script setup>
import { useAppProjectActions } from '@/composables/useAppProjectActions';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    app: { type: Object, required: true },
    panelRoute: { type: Function, required: true },
});
const emit = defineEmits(['edit']);

const { busy, live, control, checkStatus, remove } = useAppProjectActions(() => props.app, props.panelRoute);
</script>

<template>
    <div class="flex flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-700">
            <div class="min-w-0">
                <p class="flex items-center gap-2 truncate font-semibold"><i class="bi bi-hexagon text-emerald-600"></i>{{ app.name }}</p>
                <p class="mt-0.5 truncate font-mono text-xs text-slate-500 dark:text-slate-400">{{ app.working_directory }}</p>
            </div>
            <span
                class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                :class="app.status === 'running' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300'"
            >{{ app.status === 'running' ? t('nodeApps.card.running') : t('nodeApps.card.stopped') }}</span>
        </div>

        <dl class="grid grid-cols-3 gap-2 px-4 py-3 text-xs">
            <div><dt class="text-slate-500 dark:text-slate-400">{{ t('nodeApps.card.port') }}</dt><dd class="font-mono">{{ app.port }}</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">Node.js</dt><dd>{{ app.version || t('nodeApps.card.default') }}</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">{{ t('nodeApps.card.user') }}</dt><dd class="truncate">{{ app.site_owner }}</dd></div>
            <div class="col-span-3"><dt class="text-slate-500 dark:text-slate-400">{{ t('nodeApps.card.runs') }}</dt><dd class="truncate font-mono">{{ app.start_command || `node ${app.entry_file}` }}</dd></div>
        </dl>

        <div class="px-4 pb-3 text-xs">
            <p class="mb-1 text-slate-500 dark:text-slate-400">{{ t('nodeApps.card.publicOn') }}</p>
            <div v-if="app.shares.length" class="flex flex-wrap gap-1">
                <a v-for="share in app.shares" :key="share.id" :href="share.url" target="_blank" rel="noopener" class="rounded bg-blue-50 px-2 py-0.5 font-mono text-blue-700 hover:underline dark:bg-blue-900/30 dark:text-blue-300" :class="{ 'opacity-50': !share.enabled }">{{ share.url }}</a>
            </div>
            <p v-else class="text-slate-500 dark:text-slate-400"><i18n-t keypath="nodeApps.card.notPublic" scope="global"><template #link><a :href="panelRoute('rules.port-shares.index')" class="text-blue-600 hover:underline dark:text-blue-400">{{ t('nodeApps.card.publishLink') }}</a></template></i18n-t></p>
        </div>

        <p v-if="live" class="mx-4 mb-3 rounded bg-slate-50 px-2 py-1 text-xs dark:bg-slate-900">
            <span v-if="live.error" class="text-red-600">{{ live.error }}</span>
            <template v-else>systemd: <b>{{ live.active_state }}</b> · {{ live.listening ? t('nodeApps.card.listening') : t('nodeApps.card.notListening') }}</template>
        </p>

        <div class="mt-auto flex flex-wrap items-center gap-1 border-t border-slate-100 px-3 py-2 dark:border-slate-700">
            <button v-if="app.status !== 'running'" @click="control('start')" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-emerald-900/30"><i class="bi bi-play-fill"></i> {{ t('nodeApps.card.start') }}</button>
            <button v-else @click="control('stop')" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50 dark:text-amber-400 dark:hover:bg-amber-900/30"><i class="bi bi-stop-fill"></i> {{ t('nodeApps.card.stop') }}</button>
            <button @click="control('restart')" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-700"><i class="bi bi-arrow-clockwise" :class="{ 'inline-block animate-spin': busy === 'restart' || busy === 'start' }"></i> {{ t('nodeApps.card.restart') }}</button>
            <button @click="checkStatus" :disabled="!!busy" class="rounded px-2 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-700"><i class="bi bi-activity"></i> {{ t('nodeApps.card.status') }}</button>
            <span class="flex-1"></span>
            <button @click="emit('edit', app)" :title="t('nodeApps.card.edit')" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200"><i class="bi bi-pencil"></i></button>
            <button @click="remove" :title="t('nodeApps.card.delete')" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"><i class="bi bi-trash"></i></button>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/composables/usePanelApi';

// What a manifest asks for, and the admin's choice of what to grant (U7).
const props = defineProps({
    review: { type: Object, required: true },
    manifestText: { type: String, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);

const manifest = computed(() => props.review.manifest);
const installed = computed(() => props.review.installed);
// An update starts from what was granted before; a new install from nothing.
const chosen = ref(installed.value ? props.review.grantable.filter((scope) => installed.value.scopes.includes(scope)) : []);
const busy = ref(false);
const error = ref('');

const isNew = (scope) => installed.value && !installed.value.scopes.includes(scope);
const label = (scope) => {
    const [area, level] = scope.split(':');
    return `${t(`apiTokens.areas.${area}`)}: ${level === 'write' ? t('apiTokens.write') : t('apiTokens.read')}`;
};

const save = async () => {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(panelRoute('system.extensions.store'), { manifest: props.manifestText, scopes: chosen.value });
        emit('saved', data);
    } catch (e) {
        error.value = errorMessage(e, t('extensions.installFailed'));
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-3 rounded-xl border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
        <div>
            <p class="text-sm font-semibold">{{ manifest.name }} <span class="font-normal text-slate-500">{{ manifest.version }}</span></p>
            <p class="text-xs text-slate-500 dark:text-slate-400"><code>{{ manifest.id }}</code><span v-if="manifest.author"> · {{ manifest.author }}</span> · {{ t('extensions.needsPanel', { range: manifest.panel }) }}</p>
            <p v-if="manifest.description" class="mt-1 text-sm">{{ manifest.description }}</p>
            <p v-if="installed" class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-300">{{ t('extensions.updateFrom', { from: installed.version, to: manifest.version }) }}</p>
        </div>

        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('extensions.asksFor') }}</p>
            <p v-if="!manifest.scopes.length" class="text-sm text-slate-500">{{ t('extensions.noScopes') }}</p>
            <label v-for="scope in manifest.scopes" :key="scope" class="mt-1 flex items-center gap-2 text-sm">
                <input v-model="chosen" type="checkbox" :value="scope" :disabled="!review.grantable.includes(scope)" class="rounded border-slate-300" />
                <span>{{ label(scope) }}</span>
                <code class="text-[11px] text-slate-400">{{ scope }}</code>
                <span v-if="isNew(scope)" class="rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ t('extensions.newScope') }}</span>
            </label>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('extensions.scopeHelp') }}</p>
        </div>

        <dl class="grid gap-1 text-xs sm:grid-cols-[8rem_1fr]">
            <dt class="text-slate-500">{{ t('extensions.page') }}</dt>
            <dd class="break-all">{{ manifest.ui ? `${manifest.ui.title} · ${manifest.ui.url}` : '-' }}</dd>
            <dt class="text-slate-500">{{ t('extensions.webhook') }}</dt>
            <dd class="break-all">{{ manifest.webhook || '-' }}</dd>
            <dt class="text-slate-500">{{ t('extensions.events') }}</dt>
            <dd>{{ manifest.events.join(', ') || '-' }}</dd>
        </dl>

        <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
        <div class="flex gap-2">
            <button type="button" :disabled="busy" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50" @click="save">{{ installed ? t('extensions.update') : t('extensions.install') }}</button>
            <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold dark:border-slate-700" @click="emit('cancel')">{{ t('extensions.cancel') }}</button>
        </div>
    </div>
</template>

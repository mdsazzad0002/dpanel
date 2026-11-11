<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePanelApi } from '@/composables/usePanelApi';

// One installed extension and what the admin can do with it (U7).
const props = defineProps({
    extension: { type: Object, required: true },
});
const emit = defineEmits(['changed', 'removed', 'rotated']);
const { t } = useI18n();
const { panelRoute, errorMessage } = usePanelApi(t);

const busy = ref(false);
const error = ref('');
const pingResult = ref('');

const when = (value) => (value ? new Date(value).toLocaleString() : '');
const params = () => ({ extension: props.extension.id });

const run = async (action) => {
    busy.value = true;
    error.value = '';
    try {
        await action();
    } catch (e) {
        error.value = errorMessage(e, t('extensions.actionFailed'));
        if (e?.response?.data?.extension) emit('changed', e.response.data.extension);
    } finally {
        busy.value = false;
    }
};

const toggle = () => run(async () => {
    const enabled = !props.extension.enabled;
    if (!enabled && !window.confirm(t('extensions.disableConfirm', { name: props.extension.name }))) return;
    const { data } = await window.axios.put(panelRoute('system.extensions.state', params()), { enabled });
    emit('changed', data.extension);
});
const ping = () => run(async () => {
    pingResult.value = '';
    const { data } = await window.axios.post(panelRoute('system.extensions.ping', params()));
    pingResult.value = data.message;
    emit('changed', data.extension);
});
const rotate = () => run(async () => {
    if (!window.confirm(t('extensions.rotateConfirm', { name: props.extension.name }))) return;
    const { data } = await window.axios.post(panelRoute('system.extensions.credentials', params()));
    emit('rotated', data);
});
const uninstall = () => run(async () => {
    if (!window.confirm(t('extensions.uninstallConfirm', { name: props.extension.name }))) return;
    await window.axios.delete(panelRoute('system.extensions.destroy', params()));
    emit('removed', props.extension.id);
});
</script>

<template>
    <article class="space-y-2 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800/80 dark:bg-slate-900/50">
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">
                    {{ extension.name }}
                    <span class="font-normal text-slate-500">{{ extension.version }}</span>
                    <span class="ml-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold" :class="extension.enabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-300'">{{ extension.enabled ? t('extensions.enabled') : t('extensions.disabled') }}</span>
                </p>
                <p class="text-xs text-slate-500 dark:text-slate-400"><code>{{ extension.identifier }}</code><span v-if="extension.author"> · {{ extension.author }}</span></p>
                <p v-if="extension.description" class="mt-1 text-sm">{{ extension.description }}</p>
            </div>
            <!-- A full page load, so the page's Content-Security-Policy applies. -->
            <a v-if="extension.enabled && extension.has_page" :href="panelRoute('system.extensions.page', params())" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white">{{ extension.ui_title || t('extensions.open') }}</a>
        </div>

        <div class="flex flex-wrap gap-1">
            <code v-for="scope in extension.scopes" :key="scope" class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] dark:bg-slate-800">{{ scope }}</code>
            <span v-if="!extension.scopes.length" class="text-xs text-slate-500">{{ t('extensions.noGranted') }}</span>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            {{ t('extensions.tokenLine', { prefix: extension.token_prefix || '-' }) }}
            · {{ extension.token_last_used_at ? t('extensions.lastUsed', { when: when(extension.token_last_used_at) }) : t('extensions.neverUsed') }}
        </p>
        <p v-if="extension.webhook_url" class="text-xs text-slate-500 dark:text-slate-400">
            {{ t('extensions.eventsLine', { events: extension.events.join(', ') || '-' }) }}
            <template v-if="extension.last_delivery_at">
                · {{ t('extensions.lastDelivery', { when: when(extension.last_delivery_at) }) }}
                <span :class="extension.last_delivery_error ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'">{{ extension.last_delivery_error || extension.last_delivery_status }}</span>
            </template>
        </p>

        <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{{ error }}</div>
        <p v-if="pingResult" class="text-xs text-emerald-700 dark:text-emerald-300">{{ pingResult }}</p>

        <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-2 dark:border-slate-800">
            <button type="button" :disabled="busy" class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold disabled:opacity-50 dark:border-slate-700" @click="toggle">{{ extension.enabled ? t('extensions.disable') : t('extensions.enable') }}</button>
            <button v-if="extension.webhook_url && extension.enabled" type="button" :disabled="busy" class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold disabled:opacity-50 dark:border-slate-700" @click="ping">{{ t('extensions.ping') }}</button>
            <button type="button" :disabled="busy" class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-semibold disabled:opacity-50 dark:border-slate-700" @click="rotate">{{ t('extensions.rotate') }}</button>
            <button type="button" :disabled="busy" class="rounded-lg border border-red-200 px-3 py-1 text-xs font-semibold text-red-600 disabled:opacity-50 dark:border-red-900" @click="uninstall">{{ t('extensions.uninstall') }}</button>
        </div>
    </article>
</template>

<script setup>
import axios from 'axios';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

defineProps({
    connections: { type: Array, default: () => [] },
    selectedId: { type: Number, default: null },
});
const emit = defineEmits(['select', 'changed', 'removed']);
const { t } = useI18n();

const busy = ref(null);
const errors = ref({});

const test = async (connection) => {
    busy.value = connection.id;
    errors.value[connection.id] = '';
    try {
        const { data } = await axios.post(route('migrations.dpanel.link.connections.test', { connection: connection.id }));
        emit('changed', data.connection);
    } catch (e) {
        if (e.response?.data?.connection) emit('changed', e.response.data.connection);
        errors.value[connection.id] = e.response?.data?.message || t('common.sessionExpired');
    } finally {
        busy.value = null;
    }
};

const remove = async (connection) => {
    if (!window.confirm(t('panelLink.removeConfirm', { name: connection.name }))) return;
    busy.value = connection.id;
    try {
        await axios.delete(route('migrations.dpanel.link.connections.destroy', { connection: connection.id }));
        emit('removed', connection.id);
    } catch (e) {
        errors.value[connection.id] = e.response?.data?.message || t('common.sessionExpired');
    } finally {
        busy.value = null;
    }
};
</script>

<template>
    <div class="space-y-2">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ t('panelLink.connections') }}</p>
        <p v-if="!connections.length" class="text-sm text-slate-500 dark:text-slate-400">{{ t('panelLink.noConnections') }}</p>
        <div
            v-for="connection in connections"
            :key="connection.id"
            class="cursor-pointer rounded-xl border p-3 transition"
            :class="connection.id === selectedId ? 'border-indigo-400 bg-indigo-50/60 dark:border-indigo-600 dark:bg-indigo-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-slate-800 dark:hover:border-slate-700'"
            @click="emit('select', connection.id)"
        >
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ connection.name }}</p>
                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ connection.base_url }}</p>
                </div>
                <span
                    class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                    :class="connection.last_error ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'"
                >{{ connection.last_error ? t('panelLink.offline') : t('panelLink.online') }}</span>
            </div>
            <div class="mt-2 flex flex-wrap gap-1 text-[11px]">
                <span v-if="connection.can_pull" class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><i class="bi bi-cloud-download"></i> {{ t('panelLink.canPull') }}</span>
                <span v-if="connection.can_push" class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><i class="bi bi-cloud-upload"></i> {{ t('panelLink.canPush') }}</span>
                <span v-if="connection.remote_version" class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300">v{{ connection.remote_version }}</span>
            </div>
            <p v-if="errors[connection.id] || connection.last_error" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ errors[connection.id] || connection.last_error }}</p>
            <div class="mt-2 flex gap-3 text-xs font-semibold" @click.stop>
                <button type="button" :disabled="busy === connection.id" class="text-indigo-600 hover:underline disabled:opacity-50 dark:text-indigo-400" @click="test(connection)">
                    <i class="bi bi-arrow-repeat"></i> {{ t('panelLink.test') }}
                </button>
                <button type="button" :disabled="busy === connection.id" class="text-red-600 hover:underline disabled:opacity-50 dark:text-red-400" @click="remove(connection)">
                    <i class="bi bi-trash3"></i> {{ t('panelLink.remove') }}
                </button>
            </div>
        </div>
    </div>
</template>

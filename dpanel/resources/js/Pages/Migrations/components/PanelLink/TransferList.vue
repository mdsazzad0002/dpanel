<script setup>
// Every transfer over every connection, newest first, with live progress.
// "Sync again" on a finished website copy or sync sends only changed files.
import axios from 'axios';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    transfers: { type: Array, default: () => [] },
    connections: { type: Array, default: () => [] },
});
const emit = defineEmits(['started', 'changed']);
const { t } = useI18n();

const busy = ref(null);
const error = ref('');

const connectionName = (id) => props.connections.find((connection) => connection.id === id)?.name ?? t('panelLink.removedConnection');
const active = (transfer) => ['queued', 'running'].includes(transfer.status);
const statusClass = (status) => ({
    completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300',
}[status] ?? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300');

const syncAgain = async (transfer) => {
    busy.value = transfer.id;
    error.value = '';
    try {
        const { data } = await axios.post(route('migrations.dpanel.link.connections.transfers', { connection: transfer.connection_id }), {
            direction: transfer.direction,
            kind: 'files',
            sites: [{ domain: transfer.domain, ...(transfer.options?.site ?? {}) }],
            delete_missing: Boolean(transfer.options?.delete_missing),
        });
        emit('started', data.transfers);
    } catch (e) {
        error.value = e.response?.data?.message || t('common.sessionExpired');
    } finally {
        busy.value = null;
    }
};

const cancel = async (transfer) => {
    busy.value = transfer.id;
    try {
        await axios.post(route('migrations.dpanel.link.transfers.cancel', { transfer: transfer.id }));
        emit('changed');
    } catch (e) {
        error.value = e.response?.data?.message || t('common.sessionExpired');
    } finally {
        busy.value = null;
    }
};

const when = (iso) => (iso ? new Date(iso).toLocaleString() : '');
</script>

<template>
    <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ t('panelLink.transfers') }}</p>
        <p v-if="error" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ error }}</p>
        <p v-if="!transfers.length" class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ t('panelLink.noTransfers') }}</p>
        <ul v-else class="mt-2 space-y-2">
            <li v-for="transfer in transfers" :key="transfer.id" class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                <div class="flex flex-wrap items-center gap-2">
                    <i :class="transfer.direction === 'pull' ? 'bi bi-cloud-download text-sky-500' : 'bi bi-cloud-upload text-violet-500'"></i>
                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {{ transfer.kind === 'panel' ? t('panelLink.wholePanel') : transfer.domain }}
                        <span class="font-normal text-slate-500 dark:text-slate-400">
                            · {{ t(`panelLink.kind.${transfer.kind}`) }}
                            · {{ transfer.direction === 'pull' ? t('panelLink.fromName', { name: connectionName(transfer.connection_id) }) : t('panelLink.toName', { name: connectionName(transfer.connection_id) }) }}
                        </span>
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusClass(transfer.status)">{{ t(`panelLink.status.${transfer.status}`) }}</span>
                </div>
                <div v-if="active(transfer)" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full bg-indigo-500 transition-all" :style="{ width: `${transfer.percent}%` }"></div>
                </div>
                <p v-if="transfer.message" class="mt-1.5 break-words text-xs" :class="transfer.status === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'">{{ transfer.message }}</p>
                <div class="mt-1.5 flex flex-wrap items-center gap-3 text-xs">
                    <span class="text-slate-400">{{ when(transfer.created_at) }}</span>
                    <button v-if="active(transfer)" type="button" :disabled="busy === transfer.id" class="font-semibold text-red-600 hover:underline disabled:opacity-50 dark:text-red-400" @click="cancel(transfer)">{{ t('common.cancel') }}</button>
                    <button
                        v-if="!active(transfer) && transfer.kind !== 'panel' && transfer.status === 'completed' && connections.some((c) => c.id === transfer.connection_id)"
                        type="button"
                        :disabled="busy === transfer.id"
                        class="font-semibold text-indigo-600 hover:underline disabled:opacity-50 dark:text-indigo-400"
                        @click="syncAgain(transfer)"
                    ><i class="bi bi-arrow-repeat"></i> {{ t('panelLink.syncAgain') }}</button>
                </div>
            </li>
        </ul>
    </div>
</template>

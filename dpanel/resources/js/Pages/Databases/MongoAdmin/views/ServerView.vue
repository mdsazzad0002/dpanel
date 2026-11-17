<script setup>
import { computed, onMounted, ref } from 'vue';
import Card from '../components/Card.vue';
import StatsTable from '../components/StatsTable.vue';
import { formatBytes, formatCount, formatDuration, toNumber, validCollectionName, validDatabaseName } from '../lib/useMongoAdmin';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Home, like mongo-express: every database, a form to create one, and the
// server's status.
const props = defineProps({
    databases: { type: Array, default: () => [] },
    run: { type: Function, required: true },
    busy: { type: String, default: '' },
});

const emit = defineEmits(['go', 'changed']);

const form = ref({ db: '', collection: '' });
const formError = ref('');
const status = ref(null);
const statusError = ref('');

const totalSize = computed(() => props.databases.reduce((sum, db) => sum + toNumber(db.size), 0));

const loadStatus = async () => {
    try {
        status.value = (await props.run({ action: 'command', db: 'admin', command: { serverStatus: 1 } }, { key: 'status' })).result;
    } catch (e) {
        statusError.value = e.message;
    }
};
onMounted(loadStatus);

const statusRows = computed(() => {
    const s = status.value;
    if (!s) return [];
    return [
        [t('databases.mongoAdmin.server.row.hostname'), s.host],
        [t('databases.mongoAdmin.server.row.version'), s.version],
        [t('databases.mongoAdmin.server.row.uptime'), formatDuration(s.uptime)],
        [t('databases.mongoAdmin.server.row.serverTime'), s.localTime?.$date ? new Date(s.localTime.$date).toLocaleString() : '—'],
        [t('databases.mongoAdmin.server.row.currentConnections'), formatCount(s.connections?.current)],
        [t('databases.mongoAdmin.server.row.availableConnections'), formatCount(s.connections?.available)],
        [t('databases.mongoAdmin.server.row.activeClients'), formatCount(s.globalLock?.activeClients?.total)],
        [t('databases.mongoAdmin.server.row.queuedOps'), formatCount(s.globalLock?.currentQueue?.total)],
        [t('databases.mongoAdmin.server.row.memResident'), `${formatCount(s.mem?.resident)} MB`],
        [t('databases.mongoAdmin.server.row.memVirtual'), `${formatCount(s.mem?.virtual)} MB`],
        [t('databases.mongoAdmin.server.row.networkIn'), formatBytes(s.network?.bytesIn)],
        [t('databases.mongoAdmin.server.row.networkOut'), formatBytes(s.network?.bytesOut)],
        [t('databases.mongoAdmin.server.row.requests'), formatCount(s.network?.numRequests)],
        [t('databases.mongoAdmin.server.row.inserts'), formatCount(s.opcounters?.insert)],
        [t('databases.mongoAdmin.server.row.queries'), formatCount(s.opcounters?.query)],
        [t('databases.mongoAdmin.server.row.updates'), formatCount(s.opcounters?.update)],
        [t('databases.mongoAdmin.server.row.deletes'), formatCount(s.opcounters?.delete)],
        [t('databases.mongoAdmin.server.row.commands'), formatCount(s.opcounters?.command)],
        [t('databases.mongoAdmin.server.row.storageEngine'), s.storageEngine?.name || '—'],
        [t('databases.mongoAdmin.server.row.process'), s.process || '—'],
    ];
});

const create = async () => {
    formError.value = '';
    const { db, collection } = form.value;
    if (!validDatabaseName(db)) {
        formError.value = t('databases.mongoAdmin.server.errDbName');
        return;
    }
    if (!validCollectionName(collection)) {
        formError.value = t('databases.mongoAdmin.server.errCollection');
        return;
    }
    try {
        await props.run({ action: 'create_collection', db, collection }, { notify: true, key: 'create_db' });
        form.value = { db: '', collection: '' };
        emit('changed');
        emit('go', db);
    } catch (e) {
        formError.value = e.message;
    }
};

const drop = async (db) => {
    if (prompt(t('databases.mongoAdmin.server.dropPrompt', { name: db.name })) !== db.name) return;
    try {
        await props.run({ action: 'drop_database', db: db.name }, { notify: true });
        emit('changed');
    } catch (e) {
        formError.value = e.message;
    }
};
</script>

<template>
    <div class="space-y-6">
        <Card :title="t('databases.mongoAdmin.server.databases')" icon="bi bi-hdd-stack" flush>
            <template #badge>
                <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ databases.length }} · {{ formatBytes(totalSize) }}</span>
            </template>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr class="border-b border-slate-200 dark:border-slate-800">
                            <th class="w-24 px-4 py-2"></th>
                            <th class="px-4 py-2">{{ t('databases.mongoAdmin.server.col.database') }}</th>
                            <th class="px-4 py-2 text-right">{{ t('databases.mongoAdmin.server.col.collections') }}</th>
                            <th class="px-4 py-2 text-right">{{ t('databases.mongoAdmin.server.col.size') }}</th>
                            <th class="w-24 px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="db in databases" :key="db.name" class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-4 py-2">
                                <button type="button" class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700" @click="emit('go', db.name)"><i class="bi bi-eye"></i> {{ t('databases.mongoAdmin.server.view') }}</button>
                            </td>
                            <td class="px-4 py-2">
                                <button type="button" class="font-medium text-slate-800 hover:text-emerald-600 dark:text-slate-100" @click="emit('go', db.name)">{{ db.name }}</button>
                                <span v-if="db.system" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ t('databases.mongoAdmin.server.system') }}</span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ db.collections.length }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ formatBytes(db.size) }}</td>
                            <td class="px-4 py-2 text-right">
                                <button v-if="!db.system" type="button" class="rounded-md border border-red-200 px-3 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40" @click="drop(db)"><i class="bi bi-trash"></i> {{ t('databases.mongoAdmin.server.del') }}</button>
                            </td>
                        </tr>
                        <tr v-if="!databases.length"><td colspan="5" class="px-4 py-6 text-center text-slate-400">{{ t('databases.mongoAdmin.server.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <form class="flex flex-wrap items-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-950/40" @submit.prevent="create">
                <label class="text-xs text-slate-500">{{ t('databases.mongoAdmin.server.dbName') }}
                    <input v-model.trim="form.db" type="text" class="mt-1 block w-48 rounded-md border-slate-300 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900" />
                </label>
                <label class="text-xs text-slate-500">{{ t('databases.mongoAdmin.server.firstCollection') }}
                    <input v-model.trim="form.collection" type="text" class="mt-1 block w-48 rounded-md border-slate-300 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900" />
                </label>
                <button type="submit" :disabled="busy === 'create_db'" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"><i class="bi bi-plus-lg"></i> {{ t('databases.mongoAdmin.server.create') }}</button>
                <p v-if="formError" class="w-full text-sm text-red-600 dark:text-red-400">{{ formError }}</p>
            </form>
        </Card>

        <StatsTable :title="t('databases.mongoAdmin.server.status')" icon="bi bi-activity" :rows="statusRows" :loading="busy === 'status'" :error="statusError" />
    </div>
</template>

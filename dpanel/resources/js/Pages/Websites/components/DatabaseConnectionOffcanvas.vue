<script setup>
import { Link } from '@inertiajs/vue3';
import Offcanvas from '@/Components/Offcanvas.vue';
import { usePanelApi } from '@/Pages/Websites/composables/usePanelApi';

const props = defineProps({
    show: { type: Boolean, default: false },
    website: { type: Object, required: true },
    databases: { type: Array, default: () => [] },
    connectedDatabase: { type: String, default: null },
    appLabel: { type: String, default: 'Project' },
    // WordPress can't run on PostgreSQL, so those rows can't be connected.
    mysqlOnly: { type: Boolean, default: false },
    connectingId: { type: String, default: '' },
});

const emit = defineEmits(['close', 'connect']);
const { panelRoute } = usePanelApi();

const engineMeta = {
    postgresql: { label: 'PostgreSQL', icon: 'bi-database', classes: 'bg-sky-500/10 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400' },
    mariadb: { label: 'MariaDB', icon: 'bi-database', classes: 'bg-amber-500/10 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' },
};
const engineOf = (db) => engineMeta[db.engine] || engineMeta.mariadb;
const isConnected = (db) => props.connectedDatabase !== null && db.database_name === props.connectedDatabase;
const isBlocked = (db) => props.mysqlOnly && db.engine === 'postgresql';
</script>

<template>
    <Offcanvas :show="show" title="Database Connection" :subtitle="`Choose which database ${appLabel} uses`" @close="emit('close')">
        <div v-if="connectedDatabase === null" class="mb-4 flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-400">
            <i class="bi bi-info-circle mt-0.5"></i>
            Couldn't read which database the project config currently uses.
        </div>
        <div v-else-if="!databases.some(isConnected)" class="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-400">
            <i class="bi bi-exclamation-triangle mt-0.5"></i>
            <span>Project config points at <strong class="font-mono">{{ connectedDatabase }}</strong>, which isn't one of this website's databases.</span>
        </div>

        <ul class="space-y-2.5">
            <li v-for="db in databases" :key="db.id"
                :class="['rounded-xl border p-3.5 transition', isConnected(db)
                    ? 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-700 dark:bg-emerald-500/10'
                    : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800/50']">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm font-semibold text-slate-800 dark:text-slate-100">{{ db.database_name }}</p>
                        <p v-if="db.database_user" class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">User: {{ db.database_user }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium', engineOf(db).classes]">
                            <i :class="['bi', engineOf(db).icon]"></i>{{ engineOf(db).label }}
                        </span>
                        <span v-if="isConnected(db)" class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-400">
                            <i class="bi bi-check-circle-fill"></i>Connected
                        </span>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <span v-if="isBlocked(db)" class="text-[11px] text-slate-500 dark:text-slate-400">{{ appLabel }} requires MySQL/MariaDB</span>
                    <span v-else></span>
                    <button type="button" :disabled="Boolean(connectingId) || isBlocked(db)"
                        :class="['inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50', isConnected(db)
                            ? 'border-emerald-300 bg-white text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:bg-slate-900 dark:text-emerald-400'
                            : 'border-cyan-300 bg-cyan-600 text-white hover:bg-cyan-700 dark:border-cyan-700']"
                        @click="emit('connect', String(db.id))">
                        <i :class="['bi', isConnected(db) ? 'bi-arrow-repeat' : 'bi-plug']"></i>
                        {{ connectingId === String(db.id) ? 'Connecting...' : (isConnected(db) ? 'Reconnect' : 'Connect') }}
                    </button>
                </div>
            </li>
        </ul>

        <Link :href="panelRoute('databases.create') + '?domain=' + encodeURIComponent(website.domain)"
            class="mt-4 flex items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:border-cyan-400 hover:text-cyan-700 dark:border-slate-700 dark:text-slate-400 dark:hover:text-cyan-400">
            <i class="bi bi-database-add"></i>
            Create New Database
        </Link>
    </Offcanvas>
</template>

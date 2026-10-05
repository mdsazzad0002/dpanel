<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    events: { type: Array, default: () => [] },
    jails: { type: Array, default: () => [] },
    whitelist: { type: Array, default: () => [] },
    clientIp: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    busy: { type: String, default: '' },
    // Rows deleted from this view by the admin.
    hidden: { type: Number, default: 0 },
});

const emit = defineEmits(['unban', 'ban', 'bulk', 'delete', 'clear', 'restore', 'refresh']);

const search = ref('');
const filter = ref('all');
// Selected IPs; several rows can share one IP and one checkbox state.
const selected = ref(new Set());

const blocked = computed(() => new Set(props.jails.flatMap((jail) => jail.banned_ips || [])));

// sshd marks a login for a user that does not exist with "invalid user" in the method.
const kindOf = (event) => {
    if (event.result === 'accepted') return 'success';
    return (event.method || '').includes('invalid user') ? 'invalid' : 'failed';
};

const KINDS = {
    success: { label: 'Success', badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' },
    failed: { label: 'Failed', badge: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' },
    invalid: { label: 'Invalid user', badge: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' },
};

const matches = (event, name) => {
    if (name === 'all') return true;
    if (name === 'blocked') return blocked.value.has(event.ip);
    return kindOf(event) === name;
};

const filters = computed(() => [
    { name: 'all', label: 'All' },
    { name: 'success', label: 'Success' },
    { name: 'failed', label: 'Failed' },
    { name: 'invalid', label: 'Invalid user' },
    { name: 'blocked', label: 'Blocked' },
].map((item) => ({ ...item, count: props.events.filter((event) => matches(event, item.name)).length })));

const failedBy = computed(() => {
    const counts = new Map();
    for (const event of props.events) {
        if (event.result === 'failed') counts.set(event.ip, (counts.get(event.ip) || 0) + 1);
    }
    return counts;
});

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return props.events.filter((event) => (
        matches(event, filter.value)
        && (!needle || event.ip.includes(needle) || event.user.toLowerCase().includes(needle))
    ));
});

const whitelisted = (ip) => props.whitelist.includes(ip);

// "Select all" leaves out your own IP so a bulk block cannot lock you out by accident.
const visibleIps = computed(() => [...new Set(rows.value.map((row) => row.ip))]
    .filter((ip) => ip !== props.clientIp));
const allSelected = computed(() => visibleIps.value.length > 0 && visibleIps.value.every((ip) => selected.value.has(ip)));

const toggle = (ip) => {
    const next = new Set(selected.value);
    next.has(ip) ? next.delete(ip) : next.add(ip);
    selected.value = next;
};
const toggleAll = () => {
    const next = new Set(selected.value);
    for (const ip of visibleIps.value) allSelected.value ? next.delete(ip) : next.add(ip);
    selected.value = next;
};
const clearSelection = () => {
    selected.value = new Set();
};

// Whitelisted IPs can be selected to delete their history, but never blocked.
const toBlock = computed(() => [...selected.value].filter((ip) => !blocked.value.has(ip) && !whitelisted(ip)));
const toUnblock = computed(() => [...selected.value].filter((ip) => blocked.value.has(ip)));
const toWhitelist = computed(() => [...selected.value].filter((ip) => !whitelisted(ip)));
const working = computed(() => props.busy === 'bulk' || props.busy === 'history');

// A finished action returns fresh jails or history; start the next selection from scratch.
watch(() => [props.jails, props.events], clearSelection);

const formatTime = (seconds) => new Date(seconds * 1000).toLocaleString();
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">SSH login history</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    The latest {{ events.length }} SSH logins from the system journal, newest first.
                    <template v-if="hidden">
                        {{ hidden }} deleted {{ hidden === 1 ? 'row is' : 'rows are' }} hidden.
                        <button type="button" :disabled="working" class="text-blue-600 hover:underline disabled:opacity-60 dark:text-blue-400" @click="emit('restore')">Show again</button>
                    </template>
                </p>
            </div>
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                <input v-model="search" type="search" placeholder="Find an IP or user" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-56 dark:border-slate-700 dark:bg-slate-800" />
                <button type="button" :disabled="loading" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="emit('refresh')">
                    {{ loading ? 'Loading…' : 'Reload' }}
                </button>
                <button type="button" :disabled="loading || working || !events.length" class="rounded-md border border-red-300 px-3 py-2 text-xs text-red-700 hover:bg-red-50 disabled:opacity-60 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/20" @click="emit('clear')">
                    {{ busy === 'history' ? 'Working…' : 'Clear history' }}
                </button>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <button
                v-for="item in filters"
                :key="item.name"
                type="button"
                :class="filter === item.name ? 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-900/30 dark:text-blue-200' : 'border-slate-200 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800'"
                class="rounded-full border px-3 py-1 text-xs"
                @click="filter = item.name"
            >
                {{ item.label }} <span class="ml-1 font-semibold">{{ item.count }}</span>
            </button>
        </div>

        <div v-if="error" class="mt-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ error }}
        </div>

        <div v-if="selected.size" class="mt-3 flex flex-wrap items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm dark:border-blue-900 dark:bg-blue-950/40">
            <span class="mr-auto">{{ selected.size }} IP{{ selected.size === 1 ? '' : 's' }} selected</span>
            <button v-if="toBlock.length" type="button" :disabled="working" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-60" @click="emit('bulk', 'ban', toBlock)">
                {{ busy === 'bulk' ? 'Working…' : `Block ${toBlock.length}` }}
            </button>
            <button v-if="toUnblock.length" type="button" :disabled="working" class="rounded-md border border-blue-300 px-3 py-1.5 text-xs text-blue-700 hover:bg-blue-100 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/30" @click="emit('bulk', 'unban', toUnblock)">
                {{ busy === 'bulk' ? 'Working…' : `Unblock ${toUnblock.length}` }}
            </button>
            <button v-if="toWhitelist.length" type="button" :disabled="working" class="rounded-md border border-emerald-300 px-3 py-1.5 text-xs text-emerald-700 hover:bg-emerald-100 disabled:opacity-60 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/30" @click="emit('bulk', 'whitelist_add', toWhitelist)">
                {{ busy === 'bulk' ? 'Working…' : `Whitelist ${toWhitelist.length}` }}
            </button>
            <button type="button" :disabled="working" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-100 disabled:opacity-60 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800" @click="emit('delete', [...selected])">
                {{ busy === 'history' ? 'Working…' : `Delete history (${selected.size})` }}
            </button>
            <button type="button" class="rounded-md px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="clearSelection">
                Clear
            </button>
        </div>

        <div class="mt-3 max-h-[32rem] overflow-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="sticky top-0 bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="w-8 px-3 py-2">
                            <input type="checkbox" :checked="allSelected" :disabled="!visibleIps.length" title="Select all IPs shown" class="rounded border-slate-300 dark:border-slate-600" @change="toggleAll" />
                        </th>
                        <th class="px-3 py-2">Time</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">User</th>
                        <th class="px-3 py-2">IP</th>
                        <th class="px-3 py-2">Method</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="index" :class="selected.has(row.ip) ? 'bg-blue-50/60 dark:bg-blue-950/30' : ''" class="border-t border-slate-200 dark:border-slate-800">
                        <td class="px-3 py-2">
                            <input type="checkbox" :checked="selected.has(row.ip)" class="rounded border-slate-300 dark:border-slate-600" @change="toggle(row.ip)" />
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-xs">{{ formatTime(row.time) }}</td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <span :class="KINDS[kindOf(row)].badge" class="rounded px-1.5 py-0.5 text-[11px] font-semibold">{{ KINDS[kindOf(row)].label }}</span>
                            <span v-if="blocked.has(row.ip)" class="ml-1 rounded bg-slate-800 px-1.5 py-0.5 text-[11px] font-semibold text-white dark:bg-slate-200 dark:text-slate-900">Blocked</span>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ row.user || '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">
                            {{ row.ip }}
                            <span v-if="row.ip === clientIp" class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">you</span>
                            <span v-if="whitelisted(row.ip)" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">whitelisted</span>
                            <span v-if="failedBy.get(row.ip)" class="ml-1 text-[10px] text-slate-500">{{ failedBy.get(row.ip) }} failed</span>
                        </td>
                        <td class="px-3 py-2 text-xs">{{ row.method }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <button v-if="blocked.has(row.ip)" type="button" :disabled="busy === row.ip" class="rounded border border-blue-300 px-2.5 py-1 text-xs text-blue-700 hover:bg-blue-50 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20" @click="emit('unban', row.ip)">
                                {{ busy === row.ip ? 'Working…' : 'Unblock' }}
                            </button>
                            <button v-else-if="!whitelisted(row.ip)" type="button" :disabled="busy === row.ip" class="rounded border border-red-300 px-2.5 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-60 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/20" @click="emit('ban', row.ip)">
                                {{ busy === row.ip ? 'Working…' : 'Block' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="7" class="px-3 py-4 text-center text-slate-500">{{ events.length ? 'No login matches.' : 'No SSH logins recorded.' }}</td>
                    </tr>
                    <tr v-if="loading && events.length === 0">
                        <td colspan="7" class="px-3 py-4 text-center text-slate-500">Loading…</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

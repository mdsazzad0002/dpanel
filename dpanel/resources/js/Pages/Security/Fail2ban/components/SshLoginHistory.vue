<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    events: { type: Array, default: () => [] },
    jails: { type: Array, default: () => [] },
    whitelist: { type: Array, default: () => [] },
    clientIp: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    busy: { type: String, default: '' },
});

defineEmits(['unban', 'ban', 'refresh']);

const search = ref('');
const result = ref('all');

const blocked = computed(() => new Set(props.jails.flatMap((jail) => jail.banned_ips || [])));

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
        (result.value === 'all' || event.result === result.value)
        && (!needle || event.ip.includes(needle) || event.user.toLowerCase().includes(needle))
    ));
});

const formatTime = (seconds) => new Date(seconds * 1000).toLocaleString();
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">SSH login history</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">The latest {{ events.length }} SSH logins from the system journal, newest first.</p>
            </div>
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                <select v-model="result" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <option value="all">All</option>
                    <option value="failed">Failed</option>
                    <option value="accepted">Successful</option>
                </select>
                <input v-model="search" type="search" placeholder="Find an IP or user" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-56 dark:border-slate-700 dark:bg-slate-800" />
                <button type="button" :disabled="loading" class="rounded-md border border-slate-300 px-3 py-2 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('refresh')">
                    {{ loading ? 'Loading…' : 'Reload' }}
                </button>
            </div>
        </div>

        <div v-if="error" class="mt-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ error }}
        </div>

        <div class="mt-3 max-h-[32rem] overflow-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="sticky top-0 bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-3 py-2">Time</th>
                        <th class="px-3 py-2">Result</th>
                        <th class="px-3 py-2">User</th>
                        <th class="px-3 py-2">IP</th>
                        <th class="px-3 py-2">Method</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="index" class="border-t border-slate-200 dark:border-slate-800">
                        <td class="whitespace-nowrap px-3 py-2 text-xs">{{ formatTime(row.time) }}</td>
                        <td class="px-3 py-2">
                            <span :class="row.result === 'accepted' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'" class="rounded px-1.5 py-0.5 text-[11px] font-semibold">
                                {{ row.result === 'accepted' ? 'Success' : 'Failed' }}
                            </span>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ row.user || '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">
                            {{ row.ip }}
                            <span v-if="row.ip === clientIp" class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">you</span>
                            <span v-if="blocked.has(row.ip)" class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">blocked</span>
                            <span v-else-if="whitelist.includes(row.ip)" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">whitelisted</span>
                            <span v-if="failedBy.get(row.ip)" class="ml-1 text-[10px] text-slate-500">{{ failedBy.get(row.ip) }} failed</span>
                        </td>
                        <td class="px-3 py-2 text-xs">{{ row.method }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <button v-if="blocked.has(row.ip)" type="button" :disabled="busy === row.ip" class="rounded border border-blue-300 px-2.5 py-1 text-xs text-blue-700 hover:bg-blue-50 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20" @click="$emit('unban', row.ip)">
                                {{ busy === row.ip ? 'Working…' : 'Unblock' }}
                            </button>
                            <button v-else-if="!whitelist.includes(row.ip)" type="button" :disabled="busy === row.ip" class="rounded border border-red-300 px-2.5 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-60 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/20" @click="$emit('ban', row.ip)">
                                {{ busy === row.ip ? 'Working…' : 'Block' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="6" class="px-3 py-4 text-center text-slate-500">{{ events.length ? 'No login matches.' : 'No SSH logins recorded.' }}</td>
                    </tr>
                    <tr v-if="loading && events.length === 0">
                        <td colspan="6" class="px-3 py-4 text-center text-slate-500">Loading…</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

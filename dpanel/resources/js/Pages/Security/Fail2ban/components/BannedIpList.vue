<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    jails: { type: Array, default: () => [] },
    whitelist: { type: Array, default: () => [] },
    clientIp: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    busy: { type: String, default: '' },
});

defineEmits(['unban', 'whitelist']);

const search = ref('');

// One row per IP, listing every jail that blocks it.
const rows = computed(() => {
    const byIp = new Map();
    for (const jail of props.jails) {
        for (const ip of jail.banned_ips || []) {
            byIp.set(ip, [...(byIp.get(ip) || []), jail.name]);
        }
    }
    const needle = search.value.trim();
    return [...byIp.entries()]
        .filter(([ip]) => !needle || ip.includes(needle))
        .map(([ip, jails]) => ({ ip, jails }));
});
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold">Blocked IPs</h2>
            <input v-model="search" type="search" placeholder="Find an IP" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm sm:w-64 dark:border-slate-700 dark:bg-slate-800" />
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <span v-for="jail in jails" :key="jail.name" class="rounded-full border border-slate-200 px-3 py-1 text-xs dark:border-slate-700">
                <strong>{{ jail.name }}</strong>: {{ jail.currently_banned }} blocked · {{ jail.currently_failed }} failing now · {{ jail.total_banned }} blocked in total
            </span>
        </div>

        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-3 py-2">IP</th>
                        <th class="px-3 py-2">Blocked by</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.ip" class="border-t border-slate-200 dark:border-slate-800">
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ row.ip }}
                            <span v-if="row.ip === clientIp" class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">you</span>
                        </td>
                        <td class="px-3 py-2 text-xs">{{ row.jails.join(', ') }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <button type="button" :disabled="busy === row.ip" class="rounded border border-blue-300 px-2.5 py-1 text-xs text-blue-700 hover:bg-blue-50 disabled:opacity-60 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900/20" @click="$emit('unban', row.ip)">
                                {{ busy === row.ip ? 'Working…' : 'Unblock' }}
                            </button>
                            <button v-if="!whitelist.includes(row.ip)" type="button" :disabled="busy === row.ip" class="ml-2 rounded border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:hover:bg-slate-800" @click="$emit('whitelist', row.ip)">
                                Unblock &amp; always allow
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!loading && rows.length === 0">
                        <td colspan="3" class="px-3 py-4 text-center text-slate-500">{{ search ? 'No blocked IP matches.' : 'No IPs are blocked right now.' }}</td>
                    </tr>
                    <tr v-if="loading">
                        <td colspan="3" class="px-3 py-4 text-center text-slate-500">Loading…</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

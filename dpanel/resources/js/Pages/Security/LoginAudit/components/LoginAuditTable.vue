<script setup>
import { CHANNEL_BADGES, RESULT_BADGES, accuracyText, coordinates, flag, formatTime, parseAgent, place } from '../loginEventFormat';

defineProps({
    events: { type: Array, default: () => [] },
    meta: { type: Object, required: true },
    loading: { type: Boolean, default: false },
    clientIp: { type: String, default: '' },
});

const emit = defineEmits(['open', 'locate', 'page']);

const who = (event) => event.user?.name || event.username || '—';
const agentLine = (event) => {
    const agent = parseAgent(event.user_agent);
    return agent ? `${agent.browser} · ${agent.os}` : '';
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between px-4 py-3">
            <h2 class="text-base font-semibold">Login history</h2>
            <span class="text-xs text-slate-500 dark:text-slate-400">{{ meta.total.toLocaleString() }} events</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-left text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <tr>
                        <th class="px-4 py-2">Time</th>
                        <th class="px-4 py-2">User</th>
                        <th class="px-4 py-2">Result</th>
                        <th class="px-4 py-2">IP address</th>
                        <th class="px-4 py-2">Location</th>
                        <th class="px-4 py-2">Device</th>
                        <th class="px-4 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-if="loading && !events.length">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-500">Loading…</td>
                    </tr>
                    <tr v-else-if="!events.length">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-500">No logins match these filters.</td>
                    </tr>
                    <tr
                        v-for="event in events"
                        :key="event.id"
                        class="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/40"
                        @click="emit('open', event)"
                    >
                        <td class="whitespace-nowrap px-4 py-2 text-xs">{{ formatTime(event.occurred_at) }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <span :class="CHANNEL_BADGES[event.channel]?.class" class="rounded px-1.5 py-0.5 text-[10px] font-medium">{{ CHANNEL_BADGES[event.channel]?.label }}</span>
                                <span class="font-medium">{{ who(event) }}</span>
                            </div>
                            <div v-if="event.actor" class="text-xs text-amber-600 dark:text-amber-400">by admin {{ event.actor.name }}</div>
                        </td>
                        <td class="px-4 py-2">
                            <span :class="RESULT_BADGES[event.result]?.class" class="rounded px-2 py-0.5 text-xs">{{ RESULT_BADGES[event.result]?.label }}</span>
                            <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ event.reason || event.method }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                            {{ event.ip_address }}
                            <span v-if="event.ip_address === clientIp" class="ml-1 rounded bg-blue-100 px-1 text-[10px] text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">you</span>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            {{ flag(event.country_code) }} {{ place(event) }}
                            <div v-if="event.device_latitude != null" class="mt-0.5 font-mono text-[11px] text-emerald-700 dark:text-emerald-400" title="Device location from the browser">
                                <i class="bi bi-crosshair" /> {{ event.device_latitude.toFixed(5) }}, {{ event.device_longitude.toFixed(5) }} {{ accuracyText(event.device_accuracy) }}
                            </div>
                        </td>
                        <td class="px-4 py-2 text-xs text-slate-500 dark:text-slate-400">{{ agentLine(event) || (event.channel === 'ssh' ? 'SSH client' : '—') }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-right">
                            <button
                                v-if="coordinates(event)"
                                type="button"
                                title="Show on map"
                                class="rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-blue-600 dark:hover:bg-slate-800"
                                @click.stop="emit('locate', event)"
                            >
                                <i class="bi bi-geo-alt" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-xs dark:border-slate-800">
            <button type="button" :disabled="meta.page <= 1 || loading" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-50 dark:border-slate-700" @click="emit('page', meta.page - 1)">Previous</button>
            <span>Page {{ meta.page }} of {{ meta.last_page }}</span>
            <button type="button" :disabled="meta.page >= meta.last_page || loading" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-50 dark:border-slate-700" @click="emit('page', meta.page + 1)">Next</button>
        </div>
    </section>
</template>

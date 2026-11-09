<script setup>
// Each account's outbound mail this hour and today against its package limit (backlog P5).
import { usePage } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const emit = defineEmits(['result']);

const page = usePage();
const panelRoute = (name) => {
    const token = page.props.panel?.token;
    return route(name, token ? { token } : {});
};

const report = ref(null);
const loading = ref(false);
const applying = ref(false);
const loadError = ref('');

const load = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await window.axios.get(panelRoute('mail-health.send-limits'), { headers: { Accept: 'application/json' } });
        report.value = data;
    } catch (error) {
        loadError.value = error?.response?.data?.message || error?.message || 'Request failed.';
    } finally {
        loading.value = false;
    }
};

const apply = async () => {
    applying.value = true;
    try {
        const { data } = await window.axios.post(panelRoute('mail-health.send-limits.apply'), {}, { headers: { Accept: 'application/json' } });
        report.value = data;
        emit('result', { level: data.ready ? 'success' : 'warning', title: 'Mail limits', message: data.message });
    } catch (error) {
        emit('result', { level: 'error', title: 'Mail limits were not applied', message: error?.response?.data?.message || error?.message || 'Request failed.' });
    } finally {
        applying.value = false;
    }
};

const percent = (used, limit) => (limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0);
const barClass = (used, limit) => {
    const value = percent(used, limit);
    if (value >= 100) return 'bg-red-500';
    if (value >= 80) return 'bg-amber-500';
    return 'bg-cyan-600';
};

onMounted(load);
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold">Sending limits</h2>
                <p class="text-xs text-slate-500">Set per package. Counted per recipient in fixed UTC hours and days; new mailboxes and domains are included within five minutes.</p>
            </div>
            <div class="flex gap-2">
                <button type="button" :disabled="loading" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="load">
                    <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': loading }"></i> Refresh
                </button>
                <button type="button" :disabled="applying" class="inline-flex items-center gap-1.5 rounded-md bg-cyan-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-cyan-700 disabled:opacity-50" @click="apply">
                    <i class="bi bi-check2-circle"></i> {{ applying ? 'Applying…' : 'Apply now' }}
                </button>
            </div>
        </div>

        <p v-if="loadError" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">{{ loadError }}</p>

        <template v-if="report">
            <div v-if="!report.ready" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <p class="font-semibold">Limits are not enforced on this server</p>
                <p class="mt-1">{{ report.reason || 'Unknown reason.' }}</p>
            </div>
            <p v-if="!report.counters" class="text-xs text-amber-600">Redis could not be read, so usage is not shown.</p>

            <div v-if="report.accounts.length" class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950/50">
                        <tr>
                            <th class="px-4 py-2">Account</th>
                            <th class="px-4 py-2">This hour</th>
                            <th class="px-4 py-2">Today</th>
                            <th class="px-4 py-2">State</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="account in report.accounts" :key="account.id">
                            <td class="px-4 py-2">
                                <p class="font-medium">{{ account.name }}</p>
                                <p class="text-xs text-slate-500">{{ account.email }} · {{ account.package }}</p>
                            </td>
                            <td v-for="slot in [{ used: account.hour, limit: account.per_hour }, { used: account.day, limit: account.per_day }]" :key="slot.limit + '-' + slot.used" class="min-w-[9rem] px-4 py-2">
                                <template v-if="slot.limit > 0">
                                    <p>{{ slot.used }} / {{ slot.limit }}</p>
                                    <div class="mt-1 h-1.5 rounded-full bg-slate-100 dark:bg-slate-800">
                                        <div class="h-1.5 rounded-full" :class="barClass(slot.used, slot.limit)" :style="{ width: `${percent(slot.used, slot.limit)}%` }"></div>
                                    </div>
                                </template>
                                <p v-else class="text-slate-500">{{ slot.used }} · no limit</p>
                            </td>
                            <td class="px-4 py-2">
                                <span v-if="account.held" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-300">Held back</span>
                                <span v-else class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">Sending</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-slate-700">
                <i class="bi bi-envelope-slash text-3xl"></i>
                <p class="mt-2 font-medium">No package sets a sending limit</p>
                <p class="text-xs">Set emails per hour or per day on a package to limit its accounts.</p>
            </div>
        </template>
    </section>
</template>

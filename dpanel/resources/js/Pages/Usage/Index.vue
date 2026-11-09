<script setup>
// Backlog P6: every account's use against its package, with alerts at 80% and 100%.
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import UsageMeter from './components/UsageMeter.vue';
import UsageHistory from './components/UsageHistory.vue';

const props = defineProps({
    scope: { type: String, default: 'Your account' },
    accounts: { type: Array, default: () => [] },
});

const search = ref('');
const onlyAlerts = ref(false);
const open = ref(null);

const columns = ['disk', 'bandwidth', 'memory', 'cpu'];
const labels = { disk: 'Disk', bandwidth: 'Bandwidth (month)', memory: 'Memory', cpu: 'CPU' };
const resource = (account, key) => account.resources.find((row) => row.key === key);
const highest = (account) => Math.max(0, ...account.resources.map((row) => row.percent ?? 0));

const rows = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.accounts
        .filter((account) => !term || `${account.name} ${account.email} ${account.package ?? ''}`.toLowerCase().includes(term))
        .filter((account) => !onlyAlerts.value || highest(account) >= 80)
        .sort((a, b) => highest(b) - highest(a));
});
const nearCount = computed(() => props.accounts.filter((account) => highest(account) >= 80).length);
</script>

<template>
    <Head title="Usage & Limits" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Usage &amp; Limits</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ scope }} · use against the package, newest reading</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="accounts.length > 1" class="flex flex-wrap items-center gap-3">
                <input v-model="search" type="search" placeholder="Search account or package…" class="w-full max-w-xs rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                <label class="inline-flex items-center gap-2 text-sm">
                    <input v-model="onlyAlerts" type="checkbox" class="rounded border-slate-300" />
                    Only at 80% or more ({{ nearCount }})
                </label>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950/50">
                        <tr>
                            <th class="px-4 py-2">Account</th>
                            <th v-for="key in columns" :key="key" class="px-4 py-2">{{ labels[key] }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/[0.06]">
                        <template v-for="account in rows" :key="account.id">
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ account.name }}</p>
                                    <p class="text-xs text-slate-500">{{ account.email }}<span v-if="account.package"> · {{ account.package }}</span></p>
                                </td>
                                <td v-for="key in columns" :key="key" class="px-4 py-3">
                                    <UsageMeter :resource="resource(account, key)" />
                                    <p v-if="account.alerts[key]" class="mt-1 text-xs" :class="account.alerts[key] >= 100 ? 'text-red-600' : 'text-amber-600'">
                                        <i class="bi bi-bell-fill"></i> {{ account.alerts[key] }}% alert sent
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" class="rounded-md px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-emerald-400/10" @click="open = open === account.id ? null : account.id">
                                        <i class="bi" :class="open === account.id ? 'bi-chevron-up' : 'bi-graph-up'"></i> 7 days
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="open === account.id" class="bg-slate-50/60 dark:bg-white/[0.02]">
                                <td :colspan="columns.length + 2" class="px-4 py-4">
                                    <UsageHistory :account="account" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div v-if="rows.length === 0" class="p-10 text-center text-slate-500">
                    <i class="bi bi-speedometer2 text-3xl"></i>
                    <p class="mt-2 font-medium">{{ accounts.length ? 'No account matches.' : 'No account has a package yet.' }}</p>
                </div>
            </div>

            <p class="text-xs text-slate-500">
                Disk is measured hourly, bandwidth every 15 minutes, CPU and memory every five minutes (only when the package limits them).
                A notice goes to the account at 80% and at 100% of a limit, once until use falls back.
            </p>
        </div>
    </AuthenticatedLayout>
</template>

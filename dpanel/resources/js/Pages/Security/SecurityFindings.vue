<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import FindingsBulkBar from './components/FindingsBulkBar.vue';

const props = defineProps({
    findings: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    websites: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const form = ref({
    status: props.filters.status || 'open',
    severity: props.filters.severity || '',
    category: props.filters.category || '',
    website: props.filters.website || '',
    search: props.filters.search || '',
});
const rows = ref(props.findings.data.map((finding) => ({ ...finding })));
const expanded = ref(null);
const busy = ref(null);
const message = ref(null);
const total = ref(props.findings.total);
const selected = ref(new Set());
const deleting = ref(false);

const allSelected = computed(() => rows.value.length > 0 && rows.value.every((finding) => selected.value.has(finding.id)));

const toggle = (id) => {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
};
const toggleAll = () => {
    selected.value = allSelected.value ? new Set() : new Set(rows.value.map((finding) => finding.id));
};

const deleteSelected = async () => {
    const ids = [...selected.value];
    if (!confirm(`Delete ${ids.length} finding${ids.length === 1 ? '' : 's'}? A problem that is still there will be reported again on the next scan.`)) return;
    deleting.value = true;
    message.value = null;
    try {
        const { data } = await axios.delete(panelRoute('security.center.findings.destroy'), { data: { ids } });
        rows.value = rows.value.filter((finding) => !selected.value.has(finding.id));
        total.value = Math.max(0, total.value - data.deleted);
        selected.value = new Set();
        // An emptied page with more pages behind it: load the next findings in.
        if (rows.value.length === 0 && total.value > 0) {
            router.reload({ preserveState: false });
            return;
        }
        message.value = { type: 'success', text: data.message };
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not delete the findings.' };
    } finally {
        deleting.value = false;
    }
};

const severityStyles = {
    critical: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300',
    medium: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    low: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    info: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

const applyFilters = () => {
    const query = Object.fromEntries(Object.entries(form.value).filter(([, value]) => value !== ''));
    router.get(panelRoute('security.center.findings'), query, { preserveState: false });
};

const setStatus = async (finding, status) => {
    busy.value = finding.id;
    message.value = null;
    try {
        const { data } = await axios.post(panelRoute('security.center.findings.status', { finding: finding.id }), { status });
        Object.assign(finding, data.finding);
    } catch (e) {
        message.value = { type: 'error', text: e.response?.data?.message || 'Could not update the finding.' };
    } finally {
        busy.value = null;
    }
};

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
</script>

<template>
    <Head title="Security Findings" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold">Security Findings</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ total }} finding(s) match the filters.</p>
                </div>
                <Link :href="panelRoute('security.center')" class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                    Back to Security Center
                </Link>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="message" :class="message.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200'" class="rounded-md border px-4 py-3 text-sm">
                {{ message.text }}
            </div>

            <form class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-3 xl:grid-cols-6 xl:items-end" @submit.prevent="applyFilters">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label>
                    <select v-model="form.status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="open">Open</option>
                        <option value="ignored">Ignored</option>
                        <option value="resolved">Resolved</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Severity</label>
                    <select v-model="form.severity" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Any</option>
                        <option v-for="severity in ['critical', 'high', 'medium', 'low', 'info']" :key="severity" :value="severity" class="capitalize">{{ severity }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Category</label>
                    <select v-model="form.category" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Any</option>
                        <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Target</label>
                    <select v-model="form.website" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Any</option>
                        <option v-if="isAdmin" value="server">Server</option>
                        <option v-for="website in websites" :key="website.id" :value="website.id">{{ website.domain }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Search</label>
                    <input v-model="form.search" type="search" placeholder="Title, file or rule" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                </div>
                <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">Apply</button>
            </form>

            <FindingsBulkBar v-if="selected.size" :count="selected.size" :busy="deleting" @delete="deleteSelected" @clear="selected = new Set()" />

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="w-8 px-4 py-3">
                                <input type="checkbox" :checked="allSelected" :disabled="!rows.length" title="Select all findings on this page" class="rounded border-slate-300 dark:border-slate-600" @change="toggleAll">
                            </th>
                            <th class="px-4 py-3">Severity</th>
                            <th class="px-4 py-3">Finding</th>
                            <th class="px-4 py-3">Target</th>
                            <th class="px-4 py-3">Last seen</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="finding in rows" :key="finding.id">
                            <tr :class="selected.has(finding.id) ? 'bg-blue-50/60 dark:bg-blue-950/30' : ''" class="cursor-pointer border-t border-slate-200 align-top hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50" @click="expanded = expanded === finding.id ? null : finding.id">
                                <td class="px-4 py-3" @click.stop>
                                    <input type="checkbox" :checked="selected.has(finding.id)" class="rounded border-slate-300 dark:border-slate-600" @change="toggle(finding.id)">
                                </td>
                                <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs capitalize" :class="severityStyles[finding.severity]">{{ finding.severity }}</span></td>
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ finding.title }}</p>
                                    <p class="text-xs text-slate-500"><span class="font-mono">{{ finding.rule_id }}</span><span v-if="finding.file_path"> · {{ finding.file_path }}<span v-if="finding.line_number">:{{ finding.line_number }}</span></span></p>
                                </td>
                                <td class="px-4 py-3">{{ finding.website?.domain || 'Server' }}</td>
                                <td class="px-4 py-3 text-xs">{{ formatDate(finding.last_seen_at) }}</td>
                                <td class="px-4 py-3 capitalize">{{ finding.status }}</td>
                                <td class="px-4 py-3 text-right" @click.stop>
                                    <div class="flex justify-end gap-2">
                                        <button v-if="finding.status !== 'ignored'" type="button" :disabled="busy === finding.id" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="setStatus(finding, 'ignored')">Ignore</button>
                                        <button v-if="finding.status === 'open'" type="button" :disabled="busy === finding.id" class="rounded-md border border-emerald-300 px-2 py-1 text-xs text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:border-emerald-700 dark:text-emerald-300" @click="setStatus(finding, 'resolved')">Mark resolved</button>
                                        <button v-if="finding.status !== 'open'" type="button" :disabled="busy === finding.id" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="setStatus(finding, 'open')">Reopen</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="expanded === finding.id" class="bg-slate-50 dark:bg-slate-800/40">
                                <td colspan="7" class="px-4 py-3 text-sm">
                                    <p>{{ finding.description }}</p>
                                    <pre v-if="finding.evidence" class="mt-2 overflow-x-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100">{{ finding.evidence }}</pre>
                                    <p v-if="finding.recommendation" class="mt-2"><span class="font-semibold">Recommendation:</span> {{ finding.recommendation }}</p>
                                    <p class="mt-2 text-xs text-slate-500">
                                        First seen {{ formatDate(finding.first_seen_at) }}
                                        <span v-if="finding.auto_fix_available"> · Auto-fix will be available in a later release.</span>
                                    </p>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="rows.length === 0">
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">No findings match these filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="findings.last_page > 1" class="flex flex-wrap gap-2">
                <template v-for="link in findings.links" :key="link.label">
                    <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 dark:border-slate-700'"><span v-html="link.label" /></Link>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

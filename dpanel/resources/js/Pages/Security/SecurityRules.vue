<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    rules: { type: Array, default: () => [] },
});

const page = usePage();
const panelToken = computed(() => String(page.props.panel?.token || ''));
const panelRoute = (name, params = {}) => (
    panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
);

const rules = ref(props.rules.map((rule) => ({ ...rule })));
const toggling = ref(null);
const message = ref(null);
const search = ref('');
const category = ref('');
const expanded = ref(null);

const severityStyles = {
    critical: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300',
    medium: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    low: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    info: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

const categories = computed(() => [...new Set(rules.value.map((rule) => rule.category))].sort());
const enabledCount = computed(() => rules.value.filter((rule) => rule.enabled).length);

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return rules.value.filter((rule) => (
        (!category.value || rule.category === category.value)
        && (!needle || rule.rule_id.toLowerCase().includes(needle) || rule.name.toLowerCase().includes(needle))
    ));
});

const toggleRule = async (rule) => {
    toggling.value = rule.rule_id;
    message.value = null;
    try {
        const { data } = await axios.post(panelRoute('security.center.rules.toggle', { rule: rule.rule_id }), { enabled: !rule.enabled });
        rule.enabled = data.rule.enabled;
    } catch (e) {
        message.value = e.response?.data?.message || 'Could not update the rule.';
    } finally {
        toggling.value = null;
    }
};
</script>

<template>
    <Head title="Detection Rules" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-lg font-semibold">Detection Rules</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ enabledCount }} of {{ rules.length }} rules enabled. Disabled rules are skipped when scan results are saved.</p>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="message" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ message }}
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex flex-wrap gap-2">
                    <select v-model="category" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">All categories</option>
                        <option v-for="name in categories" :key="name" :value="name" class="capitalize">{{ name }}</option>
                    </select>
                    <input v-model="search" type="search" placeholder="Find a rule" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm sm:max-w-xs dark:border-slate-700 dark:bg-slate-800">
                </div>

                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-3 py-2">Rule</th>
                                <th class="px-3 py-2">Name</th>
                                <th class="px-3 py-2">Category</th>
                                <th class="px-3 py-2">Severity</th>
                                <th class="px-3 py-2 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="rule in rows" :key="rule.rule_id">
                                <tr class="cursor-pointer border-t border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50" @click="expanded = expanded === rule.rule_id ? null : rule.rule_id">
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ rule.rule_id }}</td>
                                    <td class="px-3 py-2">{{ rule.name }}</td>
                                    <td class="px-3 py-2 capitalize">{{ rule.category }}</td>
                                    <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs capitalize" :class="severityStyles[rule.severity]">{{ rule.severity }}</span></td>
                                    <td class="px-3 py-2 text-right" @click.stop>
                                        <button type="button" :disabled="toggling === rule.rule_id" class="rounded-md border px-2 py-1 text-xs disabled:opacity-50" :class="rule.enabled ? 'border-emerald-300 text-emerald-700 dark:border-emerald-700 dark:text-emerald-300' : 'border-slate-300 text-slate-500 dark:border-slate-700'" @click="toggleRule(rule)">
                                            {{ rule.enabled ? 'Enabled' : 'Disabled' }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expanded === rule.rule_id" class="bg-slate-50 dark:bg-slate-800/40">
                                    <td colspan="5" class="px-3 py-3 text-sm">
                                        <p v-if="rule.description">{{ rule.description }}</p>
                                        <p v-if="rule.remediation" class="mt-1"><span class="font-semibold">Fix:</span> {{ rule.remediation }}</p>
                                        <p class="mt-1 text-xs text-slate-500">Detection: {{ rule.detection_type }}</p>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="rows.length === 0">
                                <td colspan="5" class="px-3 py-4 text-center text-slate-500">No rule matches.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

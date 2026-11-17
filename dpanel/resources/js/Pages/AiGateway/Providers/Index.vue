<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { reactive } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

const props = defineProps({
    providers: { type: Array, default: () => [] },
    drivers: { type: Array, default: () => [] },
    defaultModelSeed: { type: Object, default: () => ({}) },
});

const toggleForm = useForm({});
const deleteForm = useForm({});

const driverLabel = (driver) => props.drivers.find((d) => d.driver === driver)?.label || driver;

const toggle = (p) => {
    toggleForm.patch(panelRoute('ai-gateway.providers.toggle', { provider: p.id }), {
        is_active: !p.is_active,
    });
};

const remove = (p) => {
    if (!confirm(t('aiGateway.providers.deleteConfirm', { name: p.name }))) return;
    deleteForm.delete(panelRoute('ai-gateway.providers.destroy', { provider: p.id }));
};

const testing = reactive({});
const testResults = reactive({});

const test = async (p) => {
    testing[p.id] = true;
    try {
        const { data } = await axios.post(panelRoute('ai-gateway.providers.test', { provider: p.id }));
        testResults[p.id] = data;
    } catch (e) {
        testResults[p.id] = { ok: false, message: e.response?.data?.message || t('aiGateway.providers.requestFailed') };
    } finally {
        testing[p.id] = false;
    }
};
</script>

<template>
    <Head :title="t('aiGateway.providers.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">{{ t('aiGateway.providers.title') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('aiGateway.providers.subtitle') }}</p>
                </div>
                <Link :href="panelRoute('ai-gateway.providers.create')" class="rounded-md bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">+ {{ t('aiGateway.providers.add') }}</Link>
            </div>
        </template>

        <div class="space-y-4">
            <div v-if="page.props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ page.props.flash.success }}</div>
            <div v-if="page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ page.props.flash.error }}</div>

            <div v-if="!providers.length" class="rounded-lg border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                <i18n-t keypath="aiGateway.providers.empty" scope="global"><template #link><Link :href="panelRoute('ai-gateway.providers.create')" class="ml-1 text-blue-600 hover:underline">{{ t('aiGateway.providers.addFirst') }}</Link></template></i18n-t>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.providers.col.provider') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.providers.col.driver') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.providers.col.defaultModel') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.providers.col.models') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.providers.col.status') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('aiGateway.providers.col.health') }}</th>
                            <th class="px-4 py-3 text-right font-medium">{{ t('aiGateway.providers.col.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="p in providers" :key="p.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-4 py-3 font-medium">{{ p.name }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ driverLabel(p.driver) }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ p.default_model || '—' }}</td>
                            <td class="px-4 py-3">
                                <Link :href="panelRoute('ai-gateway.models.index', { provider: p.id })" class="text-slate-600 hover:underline dark:text-slate-300">{{ p.models_count }}</Link>
                            </td>
                            <td class="px-4 py-3">
                                <button @click="toggle(p)" class="rounded px-2 py-0.5 text-xs font-medium" :class="p.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">
                                    {{ p.is_active ? t('aiGateway.providers.active') : t('aiGateway.providers.inactive') }}
                                </button>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="testing[p.id]" class="text-xs text-slate-400">{{ t('aiGateway.providers.testing') }}</span>
                                <span v-else-if="testResults[p.id]" class="text-xs" :class="testResults[p.id].ok ? 'text-emerald-600' : 'text-red-500'" :title="testResults[p.id].message">{{ testResults[p.id].ok ? t('aiGateway.providers.ok') : t('aiGateway.providers.fail') }}</span>
                                <span v-else-if="p.last_test_status" class="text-xs" :class="p.last_test_status === 'ok' ? 'text-emerald-600' : 'text-red-500'" :title="p.last_test_message">{{ p.last_test_status === 'ok' ? t('aiGateway.providers.ok') : p.last_test_status }}</span>
                                <span v-else class="text-xs text-slate-400">{{ t('aiGateway.providers.untested') }}</span>
                            </td>
                            <td class="space-x-1 px-4 py-3 text-right">
                                <Link :href="panelRoute('ai-gateway.providers.edit', { provider: p.id })" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700">{{ t('aiGateway.providers.edit') }}</Link>
                                <Link
                                    v-if="p.models_count > 0"
                                    :href="panelRoute('ai-gateway.chat', { provider: p.id })"
                                    class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700"
                                >{{ t('aiGateway.providers.chat') }}</Link>
                                <span v-else class="rounded border border-slate-200 px-2 py-1 text-xs text-slate-300 dark:border-slate-700 dark:text-slate-600" :title="t('aiGateway.providers.addModelFirst')">{{ t('aiGateway.providers.chat') }}</span>
                                <button @click="test(p)" :disabled="testing[p.id]" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100 disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-700">{{ t('aiGateway.providers.test') }}</button>
                                <button @click="remove(p)" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-700">{{ t('aiGateway.providers.delete') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

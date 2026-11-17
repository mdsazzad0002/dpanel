<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const page = usePage();
const panelToken = page.props.panel?.token;
const panelRoute = (name, params = {}) => (
    panelToken ? route(name, { token: panelToken, ...params }) : route(name, params)
);

defineProps({
    provider: { type: Object, default: null },
    hasModels: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});
defineEmits(['connect']);
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"
        :class="provider ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950' : 'border-indigo-200 bg-indigo-50 dark:border-indigo-800 dark:bg-indigo-950'">
        <div class="text-sm">
            <template v-if="provider">
                <div class="font-medium text-emerald-800 dark:text-emerald-300">
                    {{ t('aiGateway.localLlm.connect.connected', { name: provider.name }) }}<span v-if="!provider.is_active"> {{ t('aiGateway.localLlm.connect.disabled') }}</span>
                </div>
                <div class="mt-0.5 text-xs text-emerald-700 dark:text-emerald-400">
                    {{ provider.models.length ? t('aiGateway.localLlm.connect.modelsAvailable', { count: provider.models.length }) : t('aiGateway.localLlm.connect.noModels') }}
                </div>
            </template>
            <template v-else>
                <div class="font-medium text-indigo-800 dark:text-indigo-300">{{ t('aiGateway.localLlm.connect.notUsed') }}</div>
                <div class="mt-0.5 text-xs text-indigo-700 dark:text-indigo-400">{{ t('aiGateway.localLlm.connect.notUsedHelp') }}</div>
            </template>
        </div>
        <div class="flex items-center gap-2">
            <Link v-if="provider" :href="panelRoute('ai-gateway.chat')" class="rounded-md border border-emerald-300 px-3 py-1.5 text-xs hover:bg-emerald-100 dark:border-emerald-700 dark:hover:bg-emerald-900">{{ t('aiGateway.localLlm.connect.openChat') }}</Link>
            <button @click="$emit('connect')" :disabled="busy || !hasModels"
                :title="hasModels ? '' : t('aiGateway.localLlm.connect.downloadFirst')"
                class="rounded-md bg-blue-600 px-3 py-1.5 text-xs text-white hover:bg-blue-700 disabled:opacity-50">
                {{ busy ? t('aiGateway.localLlm.connect.syncing') : (provider ? t('aiGateway.localLlm.connect.sync') : t('aiGateway.localLlm.connect.connect')) }}
            </button>
        </div>
    </div>
</template>
